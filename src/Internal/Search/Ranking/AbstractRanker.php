<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

abstract class AbstractRanker
{
    abstract public function calculate(int $totalQueryTokenCount, array $termPositions): float;
}
