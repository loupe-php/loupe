<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class WordCount extends AbstractRanker
{
    public function calculate(array $searchableAttributes, int $totalQueryTokenCount, array $termPositions): float
    {
        $matchedTokens = array_filter(
            $termPositions,
            fn ($termArray) => !(\count($termArray) === 1 && $termArray[0][0] === 0)
        );

        return \count($matchedTokens) / $totalQueryTokenCount;
    }
}
