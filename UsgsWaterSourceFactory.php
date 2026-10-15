<?php

namespace Omnilab\UsgsWater;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The USGS's water data (Water Data OGC APIs): the series of a monitoring
 * location, as recorded or daily, the location itself, a search by name -
 * no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     api_key: ~                       # an api.data.gov key for the USGS's water APIs: a higher rate limit (sent as X-Api-Key)
 *     radius_km: 25                    # how far from a Point the nearest monitoring location is looked for
 *     throttle: 0                      # seconds between two calls
 *     base_uri: 'https://api.waterdata.usgs.gov/ogcapi/v1/'
 */
final class UsgsWaterSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'usgs-water',
            'omnilab.required_options' => [],
            'api_key' => null,
            'radius_km' => 25.0,
            'throttle' => 0.0,
            'base_uri' => UsgsWaterSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('api_key');

        return new UsgsWaterSource(
            $this->http ?? HttpClient::create(),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', UsgsWaterSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)] + (null === $key || '' === $key ? [] : ['X-Api-Key' => (string) $key]),
            (float) $c->get('throttle'),
        );
    }
}
