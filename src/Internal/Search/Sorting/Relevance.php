<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class Relevance extends AbstractSorter
{
    /**
     * @var array<string, array<string, float>>
     */
    protected static array $attributeWeightValuesCache = [];

    public function __construct(
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        if ($searcher->getTokens()->empty()) {
            return;
        }

        $positionsPerDocument = [];

        foreach ($searcher->getTokens()->all() as $token) {
            // COALESCE() makes sure that if the token does not match a document, we don't have NULL but a 0 which is important
            // for the relevance split. Otherwise, the relevance calculation cannot know which of the documents did not match
            // because it's just a ";" separated list.
            $positionsPerDocument[] = sprintf(
                "SELECT (SELECT COALESCE(group_concat(DISTINCT position || ':' || attribute), '0') FROM %s WHERE %s.id=document) AS %s",
                $searcher->getCTENameForToken(Searcher::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token),
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                Searcher::RELEVANCE_ALIAS . '_per_term',
            );
        }

        if ($positionsPerDocument === []) {
            return;
        }

        $searchableAttributes = $engine->getConfiguration()->getSearchableAttributes();
        $weights = static::calculateIntrinsicAttributeWeights($searchableAttributes);

        $select = sprintf(
            "loupe_relevance((SELECT group_concat(%s, ';') FROM (%s)), %s, '%s') AS %s",
            Searcher::RELEVANCE_ALIAS . '_per_term',
            implode(' UNION ALL ', $positionsPerDocument),
            $searcher->getTokens()->count(),
            implode(';', array_map(fn ($attr, $weight) => "{$attr}:{$weight}", array_keys($weights), $weights)),
            Searcher::RELEVANCE_ALIAS,
        );

        $searcher->getQueryBuilder()->addSelect($select);

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of
        // our internal null or empty value
        $searcher->getQueryBuilder()->addOrderBy(Searcher::RELEVANCE_ALIAS, $this->direction->getSQL());

        // Apply threshold
        $threshold = $searcher->getSearchParameters()->getRankingScoreThreshold();
        if ($threshold > 0) {
            $searcher->getQueryBuilder()->andWhere(Searcher::RELEVANCE_ALIAS . '>= ' . $threshold);
        }
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm
     * @param array<string, float> $attributeWeights
     */
    public static function calculateAttributeWeightFactor(array $positionsPerTerm, array $attributeWeights): float
    {
        $matchedAttributes = array_reduce(
            $positionsPerTerm,
            fn ($result, $term) => array_merge($result, array_map(fn ($position) => $position[1], $term)),
            []
        );

        $matchedAttributeWeights = array_map(fn ($attribute) => $attributeWeights[$attribute] ?? 1, $matchedAttributes);

        return array_reduce($matchedAttributeWeights, fn ($result, $weight) => $result * $weight, 1);
    }

    /**
     * @param array<int, string> $searchableAttributes
     * @return array<string, int>
     */
    public static function calculateIntrinsicAttributeWeights(array $searchableAttributes): array
    {
        if ($searchableAttributes === ['*']) {
            return [];
        }

        // Assign decreasing weights to each attribute
        // ['title', 'summary', 'body] → ['title' => 1, 'summary' => 0.8, 'body' => 0.8 ^ 2]
        $weight = 1;
        return array_reduce(
            $searchableAttributes,
            function ($result, $attribute) use (&$weight) {
                $result[$attribute] = round($weight, 2);
                $weight *= 0.8;
                return $result;
            },
            []
        );
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm
     */
    public static function calculateMatchCountFactor(array $positionsPerTerm, int $totalQueryTokenCount): float
    {
        $matchedTokens = array_filter(
            $positionsPerTerm,
            fn ($termArray) => !(\count($termArray) === 1 && $termArray[0][0] === 0)
        );

        return \count($matchedTokens) / $totalQueryTokenCount;
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm The positions MUST be ordered ASC
     */
    public static function calculateProximityFactor(array $positionsPerTerm, float $decayFactor = 0.1): float
    {
        $allAdjacent = true;
        $totalProximity = 0;
        $totalTermsRelevantForProximity = \count($positionsPerTerm) - 1;
        $positionPrev = null;

        foreach ($positionsPerTerm as $positions) {
            if ($positionPrev === null) {
                [$position] = $positions[0];
                $positionPrev = $position;
                continue;
            }

            $distance = 0;

            foreach ($positions as $positionAndAttribute) {
                [$position] = $positionAndAttribute;
                if ($position > $positionPrev) {
                    $distance = $position - $positionPrev;
                    $positionPrev = $position;
                    break;
                }
            }

            if ($distance !== 1) {
                $allAdjacent = false;
            }

            // Calculate proximity with decay function using the distance
            $proximity = exp(-$decayFactor * $distance);
            $totalProximity += $proximity;
        }

        return $allAdjacent ? 1.0 : ($totalTermsRelevantForProximity > 0 ? $totalProximity / $totalTermsRelevantForProximity : 0);
    }

    /**
     * Example: A string with "3:title,8:title,10:title;0;4:summary" would read as follows:
     * - The query consisted of 3 tokens (terms).
     * - The first term matched. At positions 3, 8 and 10 in the `title` attribute.
     * - The second term did not match (position 0).
     * - The third term matched. At position 4 in the `summary` attribute.
     *
     * @param string $positionsInDocumentPerTerm A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQuery(string $positionsInDocumentPerTerm, string $totalQueryTokenCount, string $attributeWeights): float
    {
        $totalQueryTokenCount = (int) $totalQueryTokenCount;
        $positionsPerTerm = static::parseTermPositions($positionsInDocumentPerTerm);
        $attributeWeightValues = static::parseAttributeWeights($attributeWeights);

        // Higher weight means more importance
        $relevanceWeights = [
            2, // 1st: Number of query terms that match in a document
            1, // 2nd: Proximity of the words
            1, // 3rd: Weight of attributes matched (use 1 as they are already weighted)
        ];

        $relevanceFactors = [
            // 1st: Number of query terms that match in a document
            self::calculateMatchCountFactor($positionsPerTerm, $totalQueryTokenCount),

            // 2nd: Proximity of the words
            self::calculateProximityFactor($positionsPerTerm),

            // 3rd: Weight of attributes matched (use 1 as they are already weighted)
            self::calculateAttributeWeightFactor($positionsPerTerm, $attributeWeightValues),
        ];

        // Calculate weighted average
        $totalFactor = array_sum(
            array_map(fn ($factor, $weight) => $factor * $weight, $relevanceFactors, $relevanceWeights)
        );

        return $totalFactor / array_sum($relevanceWeights);
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): AbstractSorter
    {
        return new self($direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return $value === Searcher::RELEVANCE_ALIAS;
    }

    /**
     * Parse an intermediate string representation of attribute weights back into an array
     *
     * "title:1;summary:0.8" -> ["title" => 1, "summary" => 0.8]
     *
     * @return array<string, float>
     */
    protected static function parseAttributeWeights(string $attributeWeights): array
    {
        if (isset(static::$attributeWeightValuesCache[$attributeWeights])) {
            return static::$attributeWeightValuesCache[$attributeWeights];
        }

        $weightValues = array_reduce(
            array_filter(explode(';', $attributeWeights)),
            function ($result, $item) {
                [$key, $value] = explode(':', $item);
                return array_merge($result, [
                    $key => (float) $value,
                ]);
            },
            []
        );

        static::$attributeWeightValuesCache[$attributeWeights] = $weightValues;

        return $weightValues;
    }

    /**
     * Parse an intermediate string representation of term positions and matches attributes
     *
     * "3:title,8:title,10:title;0;4:summary" -> [[3, "title"], [8, "title"], [10, "title"]], [[0, null]], [[4, "summary"]]
     *
     * @return array<int, array<int, array{int, string|null}>>
     */
    protected static function parseTermPositions(string $positionsInDocumentPerTerm): array
    {
        return array_map(
            fn ($term) => array_map(
                fn ($position) => [
                    (int) explode(':', "{$position}:")[0],
                    explode(':', "{$position}:")[1] ?: null,
                ],
                explode(',', $term)
            ),
            explode(';', $positionsInDocumentPerTerm)
        );
    }
}
