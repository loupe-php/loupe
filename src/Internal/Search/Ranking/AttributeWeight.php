<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

use Loupe\Loupe\Configuration;

class AttributeWeight extends AbstractRanker
{
    public static function calculate(RankingInfo $rankingInfo): float
    {
        $weights = static::calculateIntrinsicAttributeWeights($rankingInfo->getSearchableAttributes());

        // Group weights by term, making sure to go with the higher weight if multiple attributes are matched
        // So if `title` (1.0) and `summary` (0.8) are matched, the weight of `title` should be used
        $weightsPerTerm = [];

        foreach ($rankingInfo->getTermPositions()->getTerms() as $index => $term) {
            if (!$term->hasMatches()) {
                continue;
            }

            foreach ($term->getMatches() as $match) {
                if (isset($weights[$match->getAttribute()])) {
                    $weightsPerTerm[$index] = max($weightsPerTerm[$index] ?? 0, $weights[$match->getAttribute()]);
                }
            }
        }

        $totalWeight = 1;
        foreach ($weightsPerTerm as $termWeight) {
            $totalWeight = $totalWeight * $termWeight;
        }

        return $totalWeight;
    }

    /**
     * Assign decreasing weights to each attribute
     * ['title', 'summary', 'body] → ['title' => 1, 'summary' => 0.8, 'body' => 0.8 ^ 2]
     *
     * @param array<int, string> $searchableAttributes
     * @return array<string, int>
     */
    public static function calculateIntrinsicAttributeWeights(array $searchableAttributes): array
    {
        if ($searchableAttributes === ['*']) {
            return [];
        }

        $weight = 1;
        return array_reduce(
            $searchableAttributes,
            function ($result, $attribute) use (&$weight) {
                $result[$attribute] = round($weight, 2);
                $weight *= Configuration::ATTRIBUTE_RANKING_ORDER_FACTOR;
                return $result;
            },
            []
        );
    }
}
