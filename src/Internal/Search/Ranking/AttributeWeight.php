<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

use Loupe\Loupe\Configuration;

class AttributeWeight extends AbstractRanker
{
    public static function calculate(array &$searchableAttributes, array &$queryTokens, array &$termPositions): float
    {
        $weights = static::calculateIntrinsicAttributeWeights($searchableAttributes);

        // Group weights by term, making sure to go with the higher weight if multiple attributes are matched
        // So if `title` (1.0) and `summary` (0.8) are matched, the weight of `title` should be used
        $weightsPerTerm = [];
        foreach ($termPositions as $index => $term) {
            foreach ($term as [, $attribute]) {
                if ($attribute && isset($weights[$attribute])) {
                    $weightsPerTerm[$index] = max($weightsPerTerm[$index] ?? 0, $weights[$attribute]);
                }
            }
        }

        return array_reduce($weightsPerTerm, fn ($result, $weight) => $result * $weight, 1);
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
