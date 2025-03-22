<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class TypoCount extends AbstractRanker
{
    public static function calculate(RankingInfo $rankingInfo, float $decayFactor = 0.1): float
    {
        $totalNumberOfTypos = $rankingInfo->getTermPositions()->getTotalNumberOfTypos();

        if ($totalNumberOfTypos <= 0) {
            return 1.0;
        }

        return exp(-$decayFactor * $totalNumberOfTypos);
    }
}
