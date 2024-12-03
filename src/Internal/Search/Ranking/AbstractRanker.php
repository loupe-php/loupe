<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

abstract class AbstractRanker
{
    /**
     * @param array<string> $searchableAttributes
     * @param array<string> $queryTokens
     * @param array<int, array<int, array{int, string|null}>> $termPositions
     */
    abstract public static function calculate(array $searchableAttributes, array $queryTokens, array $termPositions): float;
}
