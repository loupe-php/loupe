<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

abstract class AbstractRanker
{
    /**
     * @param array<string> $searchableAttributes
     * @param array<int, array<int, array{int, string|null}>> $termPositions
     */
    abstract public function calculate(array $searchableAttributes, int $totalQueryTokenCount, array $termPositions): float;
}
