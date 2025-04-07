<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Location\Bearing\BearingSpherical;
use Location\Bounds;
use Location\Coordinate;

class GeoDistance extends Node implements AttributeFilterInterface
{
    public function __construct(
        public string $attributeName,
        public float $lat,
        public float $lng,
        public float $distance
    ) {
    }

    public function getAttribute(): string
    {
        return $this->attributeName;
    }

    /**
     * Calculates a square bounding box (BBOX) with a given distance on the map. In case of passing by poles or the
     * prime meridian, the outermost coordinates are considered.
     */
    public function getBbox(): Bounds
    {
        if ($this->distance > 40_000_000) {
            throw new \LogicException('Distance is out of range. The circumference of the earth is about 40 000km.');
        }

        $bearing = new BearingSpherical();
        $center = new Coordinate($this->lat, $this->lng);

        try {
            $north = $bearing->calculateDestination($center, 0, $this->distance);

            // Crossed the pole, let's take the northernmost point which is 90°
            if ($north->getLat() < $this->lat) {
                $north = new Coordinate(90, $this->lng);
            }
        } catch (\Exception) {
            $north = new Coordinate(90, $this->lng);
        }

        try {
            $south = $bearing->calculateDestination($center, 180, $this->distance);

            // Crossed the pole, let's take the southernmost point which is -90°
            if ($south->getLat() > $this->lat) {
                $south = new Coordinate(-90, $this->lng);
            }
        } catch (\Exception) {
            $south = new Coordinate(-90, $this->lng);
        }

        try {
            $east = $bearing->calculateDestination($center, 90, $this->distance);

            // Crossed the prime meridian, let's take the easternmost point which is 180°
            if ($east->getLng() > $this->lng) {
                $east = new Coordinate($this->lat, 180);
            }
        } catch (\Exception) {
            $east = new Coordinate($this->lat, 180);
        }

        try {
            $west = $bearing->calculateDestination($center, 270, $this->distance);

            // Crossed the prime meridian, let's take the westernmost point which is -180°
            if ($west->getLng() > $this->lng) {
                $west = new Coordinate($this->lat, -180);
            }
        } catch (\Exception) {
            $west = new Coordinate($this->lat, -180);
        }

        return new Bounds(
            new Coordinate($north->getLat(), $west->getLng()),
            new Coordinate($south->getLat(), $east->getLng()),
        );
    }

    public function getShortHash(): string
    {
        return substr(hash('sha256', (string) json_encode($this->toArray())), 0, 8);
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
