<?php

namespace Omnilab\UsgsWater;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The USGS's water data, through the Water Data OGC APIs
 * (api.waterdata.usgs.gov/ogcapi/v1), which replace the WaterServices
 * (waterservices.usgs.gov, shut down on 2027-02-22): the series of a
 * monitoring location - streamflow, gage height, water temperature, any
 * parameter by its USGS code - as recorded (the "continuous" values, every
 * 5 or 15 minutes) or as the USGS's daily statistics; a monitoring location
 * itself, an entity with its place, drainage area and the series it keeps;
 * the monitoring locations by name.
 *
 * No key needed; an api.data.gov key (api_key) raises the rate limit.
 */
final class UsgsWaterSource extends HttpSource
{
    public const BASE_URI = 'https://api.waterdata.usgs.gov/ogcapi/v1/';

    /** The most features a call answers: a larger limit is a 400. */
    public const MAX_LIMIT = 50000;

    /** The pages followed for one series query, at most (2,500,000 values). */
    private const MAX_PAGES = 50;

    /** The names series() reads for the USGS's parameter codes; any other five-digit code is read as it is. */
    public const PARAMETERS = [
        'discharge' => '00060',
        'gage_height' => '00065',
        'water_temperature' => '00010',
        'specific_conductance' => '00095',
        'dissolved_oxygen' => '00300',
        'ph' => '00400',
        'turbidity' => '63680',
        'precipitation' => '00045',
        'groundwater_level' => '72019',
    ];

    /** The USGS's statistic code for each aggregation of a day. */
    public const STATISTICS = [Series::MEAN => '00003', Series::MAX => '00001', Series::MIN => '00002', Series::SUM => '00006'];

    /**
     * @param float $radius how far from a Point the nearest monitoring location is looked for, in kilometres
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $radius = 25.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'usgs-water';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::SERIES, Capability::HISTORICAL, Capability::STATION];
    }

    /**
     * A monitoring location (USGS-01646500, usgs.site:01646500, its page):
     * its name, type, place and altitude, drainage area, state and county,
     * hydrologic unit, and each series it keeps with the period it covers.
     * Two calls; null when unknown.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $site = self::identify($id, Scheme::USGS_SITE) ?? throw NotSupportedException::identifier('usgs-water', (string) $id);
        $feature = $this->getJson('collections/monitoring-locations/items/'.rawurlencode($site->value), ['f' => 'json']);
        if (null === $feature || !\is_array($feature['properties'] ?? null)) {
            return null;
        }
        $series = $this->getJson('collections/time-series-metadata/items', [
            'f' => 'json',
            'monitoring_location_id' => $site->value,
            'limit' => 1000,
            'skipGeometry' => 'true',
            'properties' => 'parameter_code,parameter_name,unit_of_measure,computation_period_identifier,computation_identifier,statistic_id,begin,end',
        ]) ?? [];

        return $this->toEntity($feature, array_values(array_filter($series['features'] ?? [], 'is_array')));
    }

    /**
     * The monitoring locations of the USGS whose name holds the text (case
     * aside: the names are upper case), one page at a time, the next by the
     * API's cursor.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::EARTH !== $query->domain) {
            return new Page();
        }
        $text = strtoupper(trim((string) preg_replace('~\s+~u', ' ', $query->text)));
        if ('' === $text) {
            return new Page();
        }
        $data = $this->getJson('collections/monitoring-locations/items', [
            'f' => 'json',
            'filter' => \sprintf("agency_code = 'USGS' AND monitoring_location_name LIKE '%%%s%%'", str_replace(["'", '%', '_'], ["''", '', ''], $text)),
            'filter-lang' => 'cql-text',
            'limit' => max(1, min(10000, $query->limit)),
            'cursor' => $query->cursor,
        ]) ?? [];
        $entities = [];
        foreach ($data['features'] ?? [] as $feature) {
            if (\is_array($feature) && null !== Identifier::tryOf(Scheme::USGS_SITE, (string) ($feature['id'] ?? ''))) {
                $entities[] = $this->toEntity($feature);
            }
        }

        return new Page($entities, self::cursor($data['links'] ?? []), isset($data['numberMatched']) ? (int) $data['numberMatched'] : null);
    }

    /**
     * The series of a monitoring location, one per variable asked
     * ("discharge", "gage_height", "water_temperature"... or a USGS
     * parameter code, "00060"), at a station named (usgs.site) or at the
     * nearest monitoring location of a Point that keeps the first variable
     * over the period (within the configured radius):
     *
     * - SeriesQuery::DAILY: the USGS's daily values, the mean of each day
     *   unless the query aggregates otherwise (MAX, MIN, SUM: the USGS's own
     *   statistics, computed in the location's local days);
     * - any other step (HOURLY by default, PT15M, PT5M...): the values as
     *   recorded, the one at each step's start without an aggregation, the
     *   step's MAX, MIN, MEAN or SUM with one.
     *
     * The period defaults to the last seven days; a date without a time is
     * the whole day, in the query's time zone (UTC by default).
     */
    public function series(SeriesQuery $query): array
    {
        $codes = [];
        foreach ($query->variables as $variable) {
            $codes[$variable] = self::PARAMETERS[$variable] ?? (preg_match('~^\d{5}$~', $variable) ? $variable : throw NotSupportedException::operation('usgs-water', \sprintf('serve "%s" (it serves %s, or a USGS parameter code of five digits)', $variable, implode(', ', array_keys(self::PARAMETERS)))));
        }
        $daily = SeriesQuery::DAILY === $query->step;
        if (null !== $query->aggregation && !isset(self::STATISTICS[$query->aggregation])) {
            throw NotSupportedException::operation('usgs-water', 'aggregate by '.$query->aggregation);
        }
        $station = $this->station($query, Scheme::USGS_SITE);
        if (null === $station && !$query->place instanceof Point) {
            throw NotSupportedException::operation('usgs-water', 'serve a series over an area (a Point: the nearest monitoring location answers; or a usgs.site station)');
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);

        if (null === $station) {
            $found = $this->nearest($query->place, reset($codes), $daily, $from, $to);
            if (null === $found) {
                return [];
            }
            [$station, $place, $name] = $found;
        } else {
            // The station's own place and name: one call more.
            $location = $this->getJson('collections/monitoring-locations/items/'.rawurlencode($station->value), ['f' => 'json']);
            $entity = null === $location || !\is_array($location['properties'] ?? null) ? null : $this->toEntity($location);
            if (null === $entity?->place) {
                return [];
            }
            [$place, $name] = [$entity->place, $entity->label];
        }

        $statistic = $daily ? self::STATISTICS[$query->aggregation ?? Series::MEAN] : null;
        $features = $this->features($daily ? 'collections/daily/items' : 'collections/continuous/items', [
            'f' => 'json',
            'monitoring_location_id' => $station->value,
            'parameter_code' => implode(',', array_unique($codes)),
            'statistic_id' => $statistic,
            'datetime' => $daily ? $from->format('Y-m-d').'/'.$to->modify('-1 day')->format('Y-m-d') : self::utc($from).'/'.self::utc($to->modify('-1 second')),
            'limit' => self::MAX_LIMIT,
            'skipGeometry' => 'true',
            'properties' => 'time,value,parameter_code,unit_of_measure',
        ]);

        $values = [];
        $units = [];
        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];
            $code = (string) ($p['parameter_code'] ?? '');
            $time = $daily ? self::day($p['time'] ?? null, $timezone) : self::time($p['time'] ?? null);
            if (null === $time || '' === $code) {
                continue;
            }
            $values[$code][] = [$time, is_numeric($p['value'] ?? null) ? 0 + $p['value'] : null];
            $units[$code] ??= self::text($p['unit_of_measure'] ?? null);
        }

        $label = ($name ?? $station->value).' ('.$station->value.')';
        $series = [];
        foreach ($codes as $variable => $code) {
            $points = $values[$code] ?? [];
            usort($points, static fn (array $a, array $b) => $a[0] <=> $b[0]);
            if ($daily) {
                $series[] = new Series($variable, $units[$code] ?? null, $place, self::grid($points, $from, $to, new \DateInterval('P1D'), null), SeriesQuery::DAILY, $query->aggregation ?? Series::MEAN, $label, false, 'usgs-water');
                continue;
            }
            $series[] = new Series($variable, $units[$code] ?? null, $place, self::grid($points, $from, $to, new \DateInterval($query->step), $query->aggregation), $query->step, $query->aggregation, $label, false, 'usgs-water');
        }

        return $series;
    }

    /**
     * Every feature of a query, page after page by the API's next link.
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function features(string $path, array $parameters): array
    {
        $features = [];
        $data = $this->getJson($path, $parameters) ?? [];
        for ($page = 1; ; ++$page) {
            foreach ($data['features'] ?? [] as $feature) {
                if (\is_array($feature)) {
                    $features[] = $feature;
                }
            }
            $next = null;
            foreach ($data['links'] ?? [] as $link) {
                if ('next' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)) {
                    $next = $link['href'];
                }
            }
            if (null === $next) {
                return $features;
            }
            if ($page >= self::MAX_PAGES) {
                throw new ProviderException('usgs-water', \sprintf('more than %d values were asked: narrow the period', self::MAX_PAGES * self::MAX_LIMIT));
            }
            $data = $this->getJson($next) ?? [];
        }
    }

    /**
     * The nearest monitoring location of a point that keeps the parameter
     * over the period, as recorded or as daily values: its id, place and name.
     *
     * @return array{Identifier, Point, string|null}|null
     */
    private function nearest(Point $point, string $code, bool $daily, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $box = $point->around($this->radius);
        $data = $this->getJson('collections/time-series-metadata/items', [
            'f' => 'json',
            'bbox' => implode(',', $box->toArray()),
            'parameter_code' => $code,
            'computation_period_identifier' => $daily ? 'Daily' : 'Points',
            'limit' => 1000,
            'properties' => 'monitoring_location_id,begin,end',
        ]) ?? [];
        $best = null;
        foreach ($data['features'] ?? [] as $feature) {
            $p = $feature['properties'] ?? [];
            $c = $feature['geometry']['coordinates'] ?? null;
            $id = Identifier::tryOf(Scheme::USGS_SITE, (string) ($p['monitoring_location_id'] ?? ''));
            $begin = self::time($p['begin'] ?? null);
            $end = self::time($p['end'] ?? null);
            if (null === $id || !\is_array($c) || !is_numeric($c[0] ?? null) || !is_numeric($c[1] ?? null) || null === $begin || null === $end || $begin >= $to || $end < $from->modify('-1 day')) {
                continue;
            }
            $place = new Point((float) $c[1], (float) $c[0]);
            $distance = self::distance($point, $place);
            if ($distance <= $this->radius && (null === $best || $distance < $best[3])) {
                $best = [$id, $place, null, $distance];
            }
        }
        if (null === $best) {
            return null;
        }
        $name = $this->getJson('collections/monitoring-locations/items/'.rawurlencode($best[0]->value), ['f' => 'json', 'properties' => 'monitoring_location_name', 'skipGeometry' => 'true'])['properties']['monitoring_location_name'] ?? null;

        return [$best[0], $best[1], \is_string($name) ? self::text($name) : null];
    }

    /**
     * The points on the step's grid, from the start of the period to its end:
     * without an aggregation the value at each step's start (null when none
     * was recorded then), with one the step's aggregate.
     *
     * @param list<array{\DateTimeImmutable, int|float|null}> $points in time order
     *
     * @return list<array{\DateTimeImmutable, int|float|null}>
     */
    private static function grid(array $points, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateInterval $step, ?string $aggregation): array
    {
        $at = [];
        foreach ($points as [$time, $value]) {
            $at[$time->getTimestamp()] = $value;
        }
        $grid = [];
        $i = 0;
        $n = \count($points);
        for ($start = $from; $start < $to && \count($grid) < 1_000_000; $start = $end) {
            $end = $start->add($step);
            if (null === $aggregation) {
                $grid[] = [$start, $at[$start->getTimestamp()] ?? null];
                continue;
            }
            $values = [];
            for (; $i < $n && $points[$i][0] < $end; ++$i) {
                if ($points[$i][0] >= $start && null !== $points[$i][1]) {
                    $values[] = $points[$i][1];
                }
            }
            $grid[] = [$start, $values ? match ($aggregation) {
                Series::MAX => max($values),
                Series::MIN => min($values),
                Series::MEAN => round(array_sum($values) / \count($values), 3),
                Series::SUM => array_sum($values),
            } : null];
        }

        return $grid;
    }

    /**
     * @param array<string, mixed>       $feature a monitoring location
     * @param list<array<string, mixed>> $series  its time series' metadata
     */
    private function toEntity(array $feature, array $series = []): Entity
    {
        $p = $feature['properties'];
        $id = Identifier::of(Scheme::USGS_SITE, (string) ($feature['id'] ?? $p['id'] ?? ''));
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'usgs-water');
            }
        };
        $number = static fn (mixed $v): int|float|null => is_numeric($v) ? 0 + $v : null;
        $altitude = $number($p['altitude'] ?? null);
        $add('site_type', 'Site type', self::text($p['site_type'] ?? null));
        $add('agency', 'Agency', self::text($p['agency_name'] ?? null));
        $add('state', 'State', self::text($p['state_name'] ?? null));
        $add('county', 'County', self::text($p['county_name'] ?? null));
        $add('hydrologic_unit', 'Hydrologic unit code', self::text($p['hydrologic_unit_code'] ?? null));
        $add('drainage_area', 'Drainage area', null === ($a = $number($p['drainage_area'] ?? null)) ? null : new Quantity($a, 'mi²'));
        $add('contributing_drainage_area', 'Contributing drainage area', null === ($a = $number($p['contributing_drainage_area'] ?? null)) ? null : new Quantity($a, 'mi²'));
        $add('altitude', 'Altitude', null === $altitude ? null : new Quantity($altitude, 'ft', null === ($e = $number($p['altitude_accuracy'] ?? null)) ? null : (float) $e));
        $add('vertical_datum', 'Vertical datum', self::text($p['vertical_datum'] ?? null));
        $add('well_depth', 'Well depth', null === ($d = $number($p['well_constructed_depth'] ?? null)) ? null : new Quantity($d, 'ft'));
        $add('time_zone', 'Time zone', self::text($p['time_zone_abbreviation'] ?? null));
        $variables = [];
        foreach ($series as $item) {
            $s = $item['properties'] ?? [];
            $begin = self::time($s['begin'] ?? null);
            $end = self::time($s['end'] ?? null);
            if (!isset($s['parameter_code'], $s['computation_period_identifier']) || null === $begin || null === $end) {
                continue;
            }
            $kind = 'Points' === $s['computation_period_identifier'] ? 'as recorded' : strtolower((string) $s['computation_period_identifier']).(isset($s['computation_identifier']) && '' !== $s['computation_identifier'] ? ' '.strtolower((string) $s['computation_identifier']) : '');
            $variables[] = \sprintf('%s %s (%s, %s): %s/%s', $s['parameter_code'], self::text($s['parameter_name'] ?? null) ?? '', $kind, self::text($s['unit_of_measure'] ?? null) ?? '-', $begin->format('Y-m-d'), $end->format('Y-m-d'));
        }
        sort($variables);
        foreach (array_unique($variables) as $variable) {
            $add('variable', 'Series kept and the period it covers', $variable);
        }
        $c = $feature['geometry']['coordinates'] ?? null;

        return new Entity(
            id: $id,
            label: self::text($p['monitoring_location_name'] ?? null) ?? $id->value,
            domain: Domain::EARTH,
            kind: 'monitoring location',
            properties: $properties,
            url: $id->url(),
            // The altitude in feet above its vertical datum, in metres as a Point's elevation.
            place: \is_array($c) && is_numeric($c[0] ?? null) && is_numeric($c[1] ?? null) ? new Point((float) $c[1], (float) $c[0], null === $altitude ? null : round($altitude * 0.3048, 2)) : null,
            source: 'usgs-water',
        );
    }

    /** The cursor of the next page in an OGC API answer's links. */
    private static function cursor(mixed $links): ?string
    {
        foreach (\is_array($links) ? $links : [] as $link) {
            if ('next' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)) {
                parse_str((string) parse_url($link['href'], \PHP_URL_QUERY), $query);

                return isset($query['cursor']) && \is_string($query['cursor']) ? $query['cursor'] : null;
            }
        }

        return null;
    }

    /**
     * The period as two instants: a date without a time stands for the whole
     * day; no start is seven days before the end, no end is now.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $end = null === $to ? new \DateTimeImmutable('now', $timezone) : new \DateTimeImmutable($to->format('Y-m-d H:i:s'), $timezone);
        if (null !== $to && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }
        $start = null === $from ? $end->modify('-7 days') : new \DateTimeImmutable($from->format('Y-m-d H:i:s'), $timezone);

        return [$start, $end];
    }

    /** A day the API writes (2026-10-04) as its midnight in the zone. */
    private static function day(mixed $day, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        return \is_string($day) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $day) ? new \DateTimeImmutable($day, $timezone) : null;
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** The great-circle distance between two points, in kilometres. */
    private static function distance(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($h)));
    }
}
