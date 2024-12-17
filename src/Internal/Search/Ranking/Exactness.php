<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class Exactness extends AbstractRanker
{
    public static function calculate(RankingInfo $rankingInfo): float
    {
        return $rankingInfo->getTermPositions()->getTotalExactMatchingTerms() / $rankingInfo->getTermPositions()->getTotalTermsSearchedFor();
    }
}
