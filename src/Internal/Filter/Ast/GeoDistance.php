<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Location\Bearing\BearingSpherical;
use Location\Bounds;
use Location\Coordinate;
use Location\Factory\BoundsFactory;

class GeoDistance extends Node
{
    public function __construct(
        public string $attributeName,
        public float $lat,
        public float $lng,
        public float $distance
    ) {
    }

    public function getBbox(): Bounds
    {
        // In this library, the $distance is the distance from the coordinate (center) to the upper left (north-west) point.
        // We want the distance to be the one from the center to the outermost edge. That means we have to increase our distance
        // so that it can be used by the library. Long live Pythagoras.
        $distance = sqrt(pow($this->distance, 2) * 2);

        return BoundsFactory::expandFromCenterCoordinate(
            new Coordinate($this->lat, $this->lng),
            $distance,
            new BearingSpherical()
        );
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attributeName,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'distance' => $this->distance,
        ];
    }
}
