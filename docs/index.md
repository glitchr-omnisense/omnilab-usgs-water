# omnilab/usgs-water

The USGS's Water Data OGC APIs (`https://api.waterdata.usgs.gov/ogcapi/v1/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a monitoring location's
discharge, gage height, water temperature or any parameter, as recorded, hourly or daily, at a
`usgs.site` or the nearest location of a point, as series; the location itself as an entity;
locations by name. No key needed.

## Installation

```sh
composer require omnilab/usgs-water
```

```php
use Omnilab\UsgsWater\UsgsWaterSourceFactory;

$water = (new UsgsWaterSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'api_key' => null,                 // optional: an api.data.gov key for the USGS's water APIs
    'radius_km' => 25,                 // optional: how far from a Point the nearest location is looked for
]);
```

With the Symfony bundle, the factory is registered as `usgs-water` when the bundle knows it;
otherwise register `UsgsWaterSourceFactory` as a service.

## Credentials

None needed. The APIs' rate limits count per key or per address; an api.data.gov key for the
USGS's water APIs (from the USGS's signup page) raises them: give it as `api_key`, sent as
`X-Api-Key`.

## Options

The factory's options, with the defaults it applies (`UsgsWaterSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api_key` | `?string` | null | no | an api.data.gov key, sent as `X-Api-Key`: a higher rate limit |
| `radius_km` | `float` | `25` | no | how far from a `Point` the nearest monitoring location keeping the parameter is looked for, in kilometres |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the limits count per key or per address |
| `base_uri` | `string` | `https://api.waterdata.usgs.gov/ogcapi/v1/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `series`, `historical`, `station`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a monitoring location (`USGS-01646500`, `usgs.site:01646500`, its page, its API record), with the series it keeps; another scheme refused before any call |
| `search` | yes | the USGS's locations whose name holds the text (CQL2 `LIKE`), paged by the API's cursor; no total |
| `series` | yes | `discharge`, `gage_height`, `water_temperature`... or any USGS parameter code of five digits, at a `usgs.site` or at the nearest location of a `Point`, as recorded, hourly or daily (mean, max, min, sum: the API's statistics); no location near: a series without points per variable, at the point; an unknown location: an empty list |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: the APIs give no other base's id (Wikidata carries the location's number, P13020) |
| `forecast` | no |  |
| `historical` | yes | the records of the past (provisional when recent) |
| `area` | no | a box is refused before any call |
| `station` | yes | `usgs.site` |
| `cloud_cover` | no |  |

## Which API

The **Water Data OGC APIs** (`https://api.waterdata.usgs.gov/ogcapi/v1/`, v1 the current
version), not the WaterServices (`waterservices.usgs.gov/nwis/iv`): on 2026-10-15 the
WaterServices still answer, but their own page says they will be "permanently shut down on
February 22nd, 2027", slowed and interrupted on purpose from January 2027, and that "users must
migrate to https://api.waterdata.usgs.gov". The collections read:

| Collection | For |
|---|---|
| `monitoring-locations/items/{id}` | a location (`entity()`), its place and name for a series |
| `monitoring-locations/items?filter=` | `search()`: CQL2 text, `agency_code = 'USGS' AND monitoring_location_name LIKE '%...%'`, paged by the API's cursor |
| `time-series-metadata/items` | the series a location keeps (`entity()`); the locations of a box keeping a parameter (a Point's nearest) |
| `continuous/items` | the values as recorded ("continuous", every 5 or 15 minutes) |
| `daily/items` | the daily values, by statistic (`00003` mean, `00001` max, `00002` min, `00006` sum) |

Every call asks `f=json`; the series name only the properties read (`properties=time,value,
parameter_code,unit_of_measure`, `skipGeometry=true`), at most 50,000 a page (a larger limit is a
400), the next page by the `next` link, fifty pages at most for one query. The API does not
promise an order: the points are sorted.

## Its shapes

A monitoring location is an **entity** (and a station of the core's `usgs.site` scheme); what it
measures is a **series** (`Capability::STATION`: a station named answers its own records). A
`Point` answers the series of the nearest location within `radius_km` that keeps the first
variable asked over the period (as recorded, or daily for `SeriesQuery::DAILY`); a `BBox` is
refused. `search()` finds the USGS's locations by name.

## Calls

| Method | Reads | Calls |
|---|---|---|
| `entity()` | `USGS-01646500`, `usgs.site:01646500`, its page on waterdata.usgs.gov, its record in the API | two: the location, its series' metadata |
| `search()` | a text in the name (upper-cased: the names are), `limit` (10,000 at most), the cursor | one a page |
| `series()` | the variables below or any five-digit USGS parameter code; a `usgs.site` station or a `Point`; the period (seven days back by default; a date alone is the whole day, in the query's time zone, UTC by default); the step and the aggregation | the location (or, for a Point, the box's series and the nearest's name), then one call for every variable, a page per 50,000 values |

| Variable | Parameter code |
|---|---|
| `discharge` | `00060` (ft^3/s) |
| `gage_height` | `00065` (ft) |
| `water_temperature` | `00010` (degC) |
| `specific_conductance` | `00095` (uS/cm, at 25 °C) |
| `dissolved_oxygen` | `00300` (mg/l) |
| `ph` | `00400` |
| `turbidity` | `63680` (_FNU) |
| `precipitation` | `00045` |
| `groundwater_level` | `72019` (depth to water below land surface) |

The units in parentheses are those the API gave Little Falls' series on 2026-10-15. A series' unit is the API's `unit_of_measure`, as it writes it (`ft^3/s`, `ft`, `degC`). The step:

- `SeriesQuery::DAILY`: the USGS's daily values, the mean unless the query aggregates otherwise
  (`Series::MAX`, `MIN`, `SUM`: the USGS's own daily statistics, computed in the location's local
  days; a day is given at its midnight in the query's zone);
- any other step (`HOURLY`, the default; `PT15M`, `PT5M`...): the values as recorded on the
  step's grid from the start of the period - without an aggregation the value recorded at each
  step's start (null when none was), with one the step's `MAX`, `MIN`, `MEAN` or `SUM`.

A variable it does not read, another aggregation, a box or another scheme's station are refused
before any call. An unknown location is `null` (404); a Point with no location near: a series without points per variable, at the point.
A 429 is a `RateLimitedException`; a 400 (`InvalidParameterValue`) a `ProviderException` with
the API's description.

## What an entity holds

`id` (`usgs.site:USGS-01646500`), `label` (its name), `domain` earth, `kind` `monitoring
location`, `place` (its latitude and longitude; its altitude in metres, converted from feet),
`url` (its page on waterdata.usgs.gov), and:

| Key | API | Value |
|---|---|---|
| `site_type`, `agency`, `state`, `county`, `hydrologic_unit`, `time_zone` | site_type, agency_name, state_name, county_name, hydrologic_unit_code, time_zone_abbreviation | text |
| `drainage_area`, `contributing_drainage_area` | drainage_area, contributing_drainage_area | Quantity, mi² |
| `altitude`, `vertical_datum` | altitude ± altitude_accuracy, vertical_datum | Quantity, ft; text (`NAVD88`) |
| `well_depth` | well_constructed_depth | Quantity, ft (below land surface) |
| `variable` | each time series' metadata | `00060 Discharge (as recorded, ft^3/s): 1972-06-09/2026-10-15`, one property each |

A location of a search holds the same but the series it keeps (`entity()` reads them).

## The Collector

The APIs give no id of another base. Into USGS Water: omnilab/wikidata reads an item's USGS
monitoring location number (P13020) into the `usgs.site` scheme, so the `Collector` follows a
Wikidata item into this source - and offers a location's id to Wikidata, which looks items up by
it.

## What the APIs do not give here

The field measurements, the peaks, the water-quality samples (the Water Quality API), the
statistics over years, the time series' revisions and methods, the reference lists (parameter
codes, site types...), other agencies' locations (`USEPA-...`), the CQL2 filters beyond the name
and a bounding box, and the WaterServices are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Series;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\UsgsWater\UsgsWaterSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$water = (new UsgsWaterSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'api_key' => getenv('USGS_WATER_API_KEY') ?: null]);

$potomac = $water->entity('USGS-01646500');
echo $potomac->label, ' (', $potomac->id, '): ', $potomac->place, ', drainage ', $potomac->property('drainage_area'), ', ', count($potomac->properties('variable')), " series kept\n";

[$discharge] = $water->series(new SeriesQuery(['discharge'], $potomac->id, new DateTimeImmutable('2026-10-20'), new DateTimeImmutable('2026-10-26'), SeriesQuery::DAILY, Series::MEAN));
printf("%s (%s), daily means: %s\n", $discharge->label, $discharge->unit, implode(', ', $discharge->values()));

$page = $water->search(new Query('potomac river', limit: 3));
printf("%s locations named \"potomac river\"; the first %d: %s\n", $page->total ?? '?', count($page), implode('; ', array_map(static fn ($l) => $l->label, $page->items)));
```

```
POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA (usgs.site:USGS-01646500): 38.949777777778,-77.127638888889, drainage 11560 mi², 65 series kept
POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA (USGS-01646500) (ft^3/s), daily means: 5480, 4820, 4260, 3790, 3500, 3230, 
? locations named "potomac river"; the first 3: NORTH BRANCH POTOMAC RIVER NEAR KEMPTON, MD; NORTH BRANCH POTOMAC RIVER NEAR WILSON, MD; NORTH BRANCH POTOMAC RIVER AT WILSON, MD
```

(as answered on 2026-10-27, without a key; the 26th's daily mean was not published yet: a null,
the last value of the list)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        water: { factory: usgs-water, options: { mailto: '%env(OMNILAB_MAILTO)%', api_key: '%env(default::USGS_WATER_API_KEY)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call (`NotSupportedException`): a variable that is neither served nor a parameter code
of five digits, an aggregation the daily statistics do not have, a box, a station of another
scheme. A series that would take more than fifty pages of 50,000 values is a `ProviderException`
("narrow the period"). An unknown location is the API's 404, null.

## Verified, and not

Against the real APIs on 2026-10-15: the Potomac River near Washington at Little Falls by its id,
its number and its page (11,560 mi², 37.04 ± 0.17 ft NAVD88, 65 series kept, its daily discharge
since 1930-03-01); an unknown location (404, null); its discharge and gage height on 2026-10-14
hourly in one call (14,000 to 24,300 ft³/s, 4.38 to 5.26 ft), every five minutes (288 values),
the hours' maxima; its daily mean discharge from 2026-10-01 to 10-07 (2,650 to 1,610 ft³/s,
answered out of order); a point beside it giving it as the nearest location with discharge; a
search by name, two pages by the cursor. The fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-27: the Potomac at Little Falls by its id (65 series kept), its daily mean
discharge from 10-20 to 10-26, a search by name (the example above).

Not verified: a key (none created) and the rate limits (no 429 met; without a key the answers
carried no `X-RateLimit-*` header); a series of more than 50,000 values (the `next` link is
followed as the API's documentation shows).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the U.S. Geological Survey. Its water data are in the U.S. public domain; credit the
USGS ("U.S. Geological Survey, Water Data for the Nation"); recent values are provisional, as the
README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-usgs-water#terms-of-use)
says. Whoever runs the package is responsible for any use made of what it returns: see the
README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-usgs-water#responsibility)
section.
