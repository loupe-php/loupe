<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Location\Bounds;
use Location\Coordinate;

class GeoBoundingBox extends Node implements AttributeFilterInterface
{
    private Bounds $bbox;

    public function __construct(
        public string $attributeName,
        float $north,
        float $east,
        float $south,
        float $west,
    ) {
        $this->bbox = new Bounds(
            new Coordinate($north, $west),
            new Coordinate($south, $east),
        );
    }

    public function getAttribute(): string
    {
        return $this->attributeName;
    }

    public function getBbox(): Bounds
    {
        return $this->bbox;
    }

    public function getShortHash(): string
    {
        return substr(hash('sha256', (string) json_encode($this->toArray())), 0, 8);
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attributeName,
            'north' => $this->bbox->getNorth(),
            'east' => $this->bbox->getEast(),
            'south' => $this->bbox->getSouth(),
            'west' => $this->bbox->getWest(),
        ];
    }
}
