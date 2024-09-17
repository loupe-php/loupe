<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Location\Bounds;
use Location\Coordinate;

class GeoBoundingBox extends Node
{
    public function __construct(
        public string $attributeName,
        public float $north,
        public float $east,
        public float $south,
        public float $west,
    ) {
    }

    public function getBbox(): Bounds
    {
        // phpgeo bounds are top left to bottom right but meilisearch and so loupe is top right to bottom left
        return new Bounds(
            new Coordinate($this->north, $this->west),
            new Coordinate($this->south, $this->east),
        );
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attributeName,
            'north' => $this->north,
            'east' => $this->east,
            'south' => $this->south,
            'west' => $this->west,
        ];
    }
}
