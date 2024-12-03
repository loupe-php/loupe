<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class WordCount extends AbstractRanker
{
    public static function calculate(array $searchableAttributes, array $queryTokens, array $termPositions): float
    {
        return static::calculateWordCount($termPositions);
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $termPositions
     */
    public static function calculateWordCount(array $termPositions): float
    {
        $matchedTokens = array_filter(
            $termPositions,
            fn ($termArray) => !(\count($termArray) === 1 && $termArray[0][0] === 0)
        );

        return \count($matchedTokens) / count($termPositions);
    }
}
