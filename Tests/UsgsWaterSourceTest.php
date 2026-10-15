<?php

namespace Omnilab\UsgsWater\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\UsgsWater\UsgsWaterSource;
use Omnilab\UsgsWater\UsgsWaterSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.waterdata.usgs.gov/ogcapi/v1 on 2026-10-15:
 * the Potomac River near Washington, Little Falls (USGS-01646500), its
 * record and its time series' metadata; its discharge and gage height as
 * recorded on 2026-10-14 (every five minutes), its daily mean discharge
 * from 2026-10-01 to 10-07; the discharge series within 25 km of a point
 * near it; the USGS's locations named "POTOMAC RIVER NEAR WASH" and
 * "POTOMAC RIVER", two pages of three; an unknown location (404).
 */
final class UsgsWaterSourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>, array<string, string>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): UsgsWaterSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($o['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->calls[] = [$path, $query, $headers];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_ends_with($path, '/monitoring-locations/items/USGS-01646500') => 'location-01646500.json',
                str_contains($path, '/monitoring-locations/items/') => 'unknown.json',
                str_ends_with($path, '/monitoring-locations/items') => match (true) {
                    isset($query['cursor']) => 'search-potomac-2.json',
                    str_contains($query['filter'], 'NEAR WASH') => 'search-potomac-wash.json',
                    default => 'search-potomac-1.json',
                },
                str_ends_with($path, '/time-series-metadata/items') => isset($query['bbox']) ? 'nearby-discharge.json' : 'series-metadata-01646500.json',
                str_ends_with($path, '/daily/items') => 'daily-01646500-2026-10-01.json',
                str_ends_with($path, '/continuous/items') => '00060' === $query['parameter_code'] ? 'continuous-discharge-2026-10-14.json' : 'continuous-01646500-2026-10-14.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => 'unknown.json' === $fixture ? 404 : 200]);
        });
        $source = (new UsgsWaterSourceFactory($http))->create($options);
        self::assertInstanceOf(UsgsWaterSource::class, $source);

        return $source;
    }

    public function testAMonitoringLocationAndTheSeriesItKeeps(): void
    {
        $site = $this->source()->entity('USGS-01646500');

        self::assertSame('usgs.site:USGS-01646500', $site->id->key());
        self::assertSame('POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA', $site->label);
        self::assertSame([Domain::EARTH, 'monitoring location'], [$site->domain, $site->kind]);
        self::assertSame([38.9498, -77.1276, 11.29], [round($site->place->latitude, 4), round($site->place->longitude, 4), $site->place->elevation]);
        self::assertEquals(new Quantity(11560.0, 'mi²'), $site->value('drainage_area'));
        self::assertEquals(new Quantity(37.04, 'ft', 0.17), $site->value('altitude'));
        self::assertSame(['Stream', 'Maryland', 'Montgomery County', '020700081005', 'NAVD88'], [$site->value('site_type'), $site->value('state'), $site->value('county'), $site->value('hydrologic_unit'), $site->value('vertical_datum')]);
        $variables = array_map('strval', $site->properties('variable'));
        self::assertContains('00060 Discharge (as recorded, ft^3/s): 1972-06-09/2026-10-15', $variables);
        self::assertContains('00060 Discharge (daily mean, ft^3/s): 1930-03-01/2026-10-14', $variables);
        self::assertSame('https://waterdata.usgs.gov/monitoring-location/USGS-01646500/', $site->url);
        self::assertCount(2, $this->calls);
    }

    public function testByItsNumberOrPageAndUnknown(): void
    {
        $source = $this->source();

        self::assertSame('USGS-01646500', $source->entity('usgs.site:01646500')?->id->value);
        self::assertSame('USGS-01646500', $source->entity('https://waterdata.usgs.gov/monitoring-location/USGS-01646500/')?->id->value);
        self::assertNull($source->entity('USGS-00000001'));
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(NotSupportedException::class);
        try {
            $this->source()->entity('ghcn:USW00094728');
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::SERIES, Capability::HISTORICAL, Capability::STATION], $this->source()->capabilities());
    }

    public function testAStationsDischargeAndGageHeightHourlyInOneCall(): void
    {
        [$discharge, $height] = $this->source()->series(new SeriesQuery(['discharge', 'gage_height'], Identifier::of(Scheme::USGS_SITE, '01646500'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));

        self::assertSame(['discharge', 'ft^3/s', SeriesQuery::HOURLY, null, false, 'usgs-water'], [$discharge->variable, $discharge->unit, $discharge->step, $discharge->aggregation, $discharge->forecast, $discharge->source]);
        self::assertSame('POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA (USGS-01646500)', $discharge->label);
        self::assertCount(24, $discharge);
        self::assertSame([14000, 14100, 14500], \array_slice($discharge->values(), 0, 3));
        self::assertSame(24300, $discharge->max());
        self::assertSame('2026-10-14T00:00:00+00:00', $discharge->times()[0]->format(\DATE_ATOM));
        self::assertSame('ft', $height->unit);
        self::assertSame([4.38, 4.39], \array_slice($height->values(), 0, 2));
        $call = $this->calls[1];
        self::assertSame(['00060,00065', '2026-10-14T00:00:00Z/2026-10-14T23:59:59Z', '50000', 'true'], [$call[1]['parameter_code'], $call[1]['datetime'], $call[1]['limit'], $call[1]['skipGeometry']]);
    }

    public function testEveryFiveMinutesOrTheHoursMaximum(): void
    {
        $site = Identifier::of(Scheme::USGS_SITE, '01646500');
        $source = $this->source();
        [$recorded] = $source->series(new SeriesQuery(['00060'], $site, new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'), 'PT5M'));
        [$max] = $source->series(new SeriesQuery(['discharge'], $site, new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'), SeriesQuery::HOURLY, Series::MAX));

        self::assertCount(288, $recorded);
        self::assertSame('00060', $recorded->variable);
        self::assertSame([14100, 14400, 14800], \array_slice($max->values(), 0, 3));
        self::assertSame(Series::MAX, $max->aggregation);
    }

    public function testTheDailyMeans(): void
    {
        [$daily] = $this->source()->series(new SeriesQuery(['discharge'], Identifier::of(Scheme::USGS_SITE, '01646500'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));

        // The API answers the days in no order: they are sorted.
        self::assertSame([2650, 2230, 2230, 2100, 1810, 1700, 1610], $daily->values());
        self::assertSame([SeriesQuery::DAILY, Series::MEAN], [$daily->step, $daily->aggregation]);
        self::assertSame(['00003', '2026-10-01/2026-10-07'], [$this->calls[1][1]['statistic_id'], $this->calls[1][1]['datetime']]);
    }

    public function testThePointsNearestLocation(): void
    {
        [$max] = $this->source()->series(new SeriesQuery(['discharge'], new Point(38.95, -77.13), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'), SeriesQuery::HOURLY, Series::MAX));

        self::assertSame('POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA (USGS-01646500)', $max->label);
        self::assertSame(14100, $max->values()[0]);
        self::assertSame(['00060', 'Points'], [$this->calls[0][1]['parameter_code'], $this->calls[0][1]['computation_period_identifier']]);
    }

    public function testAnAreaAnotherStationOrAVariableAreRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            new SeriesQuery(['discharge'], new BBox(-77.2, 38.9, -77.0, 39.0)),
            new SeriesQuery(['discharge'], Identifier::of('ghcn', 'USW00094728')),
            new SeriesQuery(['flow'], new Point(38.95, -77.13)),
            new SeriesQuery(['discharge'], new Point(38.95, -77.13), aggregation: 'median'),
        ] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchByNameInPages(): void
    {
        $source = $this->source();
        $one = $source->search(new Query('Potomac river near wash', limit: 5));
        self::assertSame(['usgs.site:USGS-01646500'], array_map(static fn ($e) => $e->id->key(), $one->items));
        self::assertNull($one->next);
        self::assertSame("agency_code = 'USGS' AND monitoring_location_name LIKE '%POTOMAC RIVER NEAR WASH%'", $this->calls[0][1]['filter']);

        $first = $source->search(new Query('potomac river', limit: 3));
        self::assertSame('VVNHUy0wMTU5NDkzMQ', $first->next);
        $second = $source->search(new Query('potomac river', limit: 3, cursor: $first->next));
        self::assertSame('NORTH BRANCH POTOMAC RIVER AT GORMAN, MD', $second->first()->label);
        self::assertSame('VVNHUy0wMTU5NDkzMQ', $this->calls[2][1]['cursor']);
    }

    public function testAKeyIsSentAsAHeader(): void
    {
        $this->source(null, ['api_key' => 'DEMO_KEY'])->entity('USGS-01646500');

        self::assertSame('DEMO_KEY', $this->calls[0][2]['x-api-key']);
    }

    public function testTooManyCallsAndBadQueries(): void
    {
        try {
            $this->source(new MockResponse('{"error":{"code":"OVER_RATE_LIMIT","message":"You have exceeded your rate limit."}}', ['http_code' => 429]))->entity('USGS-01646500');
            self::fail('A 429.');
        } catch (RateLimitedException) {
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unknown string format: bad');
        $this->source(new MockResponse('{"code": "InvalidParameterValue", "type": "InvalidParameterValue", "description": "Unknown string format: bad"}', ['http_code' => 400]))->entity('USGS-01646500');
    }
}
