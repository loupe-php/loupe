<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

class Position
{
    public function __construct(
        public int $position,
        public int $numberOfTypos
    ) {
    }
}
