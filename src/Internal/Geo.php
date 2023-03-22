<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Location\Coordinate;
use Location\Distance\Haversine;

class Geo
{
    public static function geoDistance(float $latA, float $lngA, float $latB, float $lngB): float
    {
        // Use Haversine here, it's faster
        //dump((new Coordinate($latA, $lngA))->getDistance(new Coordinate($latB, $lngB), new Haversine()));
        // dump((int) round((new Coordinate($latA, $lngA))->getDistance(new Coordinate($latB, $lngB), new Haversine())));

        return (float) (new Coordinate($latA, $lngA))->getDistance(new Coordinate($latB, $lngB), new Haversine());
    }
}
