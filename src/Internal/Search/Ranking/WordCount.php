<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

/**
 * Ranks based on the number of matching terms vs. number of terms searched for in total.
 * E.g. if you search for "this is my hobby" then it's better if a document matches all 4 terms instead of just 3.
 */
class WordCount extends AbstractRanker
{
    public static function calculate(RankingInfo $rankingInfo): float
    {
        return $rankingInfo->getTermPositions()->getTotalMatchingTerms() / $rankingInfo->getTermPositions()->getTotalTermsSearchedFor();
    }
}
