# omnilab/usgs-water

The USGS's water data for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab), through the
Water Data OGC APIs (`api.waterdata.usgs.gov/ogcapi/v1`), which replace the WaterServices
(`waterservices.usgs.gov`, shut down on 2027-02-22): the series of a monitoring location -
streamflow, gage height, water temperature, any parameter by its USGS code - as recorded (every 5
or 15 minutes), hourly, or as the USGS's daily statistics, at a station named or at the nearest
one of a point; a monitoring location itself, an entity with its place, drainage area and the
series it keeps; the monitoring locations by name. No key needed.

```php
$water = (new UsgsWaterSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$falls = $water->entity('USGS-01646500');
$falls->label;                        // POTOMAC RIVER NEAR WASH, DC LITTLE FALLS PUMP STA
$falls->property('drainage_area');    // 11560 mi²
$falls->properties('variable');       // 00060 Discharge (daily mean, ft^3/s): 1930-03-01/2026-10-14, ...

[$discharge, $height] = $water->series(new SeriesQuery(['discharge', 'gage_height'], $falls->id,
    new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));
$discharge->values();                 // 14000 14100 14500 ... (ft^3/s, each hour's value as recorded)
$height->max();                       // 5.26 (ft)

[$daily] = $water->series(new SeriesQuery(['discharge'], $falls->id, new \DateTimeImmutable('2026-10-01'),
    new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));
$daily->values();                     // 2650 2230 2230 2100 1810 1700 1610 (the USGS's daily means)

// A point: the nearest monitoring location keeping the variable over the period.
$water->series(new SeriesQuery(['discharge'], new Point(38.95, -77.13), $from, $to, SeriesQuery::HOURLY, Series::MAX));
$water->search(new Query('potomac river'));
```

```yaml
omnilab:
    sources:
        usgs-water:
            factory: usgs-water
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                api_key: '%env(USGS_WATER_API_KEY)%' # optional: an api.data.gov key, a higher rate limit
                radius_km: 25                  # how far from a Point the nearest location is looked for
```

The earthquakes of the USGS are [omnilab/usgs](https://github.com/glitchr-studio/omnilab-usgs).

See [docs/](docs/index.md) for what is read, how it maps, and what the APIs do not give here.

## Terms of use

The USGS's water data are in the U.S. public domain; credit the USGS (for example "U.S.
Geological Survey, Water Data for the Nation") where you show them. Recent values are provisional
and may be revised (each value's approval status says so). The APIs need no key; their rate
limits, per key or per address, answer 429 once exceeded, and an api.data.gov key (sent as
`X-Api-Key`) raises them - the user obtains one from the USGS's signup page if needed.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the U.S. Geological Survey. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
