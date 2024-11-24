<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class Relevance extends AbstractSorter
{
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

        $weights = $this->calculateIntrinsicAttributeWeights($engine);

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
     * @param array<string, int> $attributeWeights
     */
    public static function calculateAttributeWeightFactor(array $positionsPerTerm, array $attributeWeights): float
    {
        $matchedAttributes = array_reduce(
            $positionsPerTerm,
            fn ($result, $term) => array_merge($result, array_map(fn ($position) => $position[1], $term)),
            []
        );

        $matchedAttributeWeights = array_map(fn ($attribute) => $attributeWeights[$attribute] ?? 1, $matchedAttributes);
        $matchedAttributeWeights = array_filter($matchedAttributeWeights, fn ($weight) => $weight !== 1);

        return \count($matchedAttributeWeights) ?
            (array_sum($matchedAttributeWeights) / \count($positionsPerTerm))
            : 1;
    }

    /**
     * @return array<string, int>
     */
    public static function calculateIntrinsicAttributeWeights(Engine $engine): array
    {
        $searchableAttributes = $engine->getConfiguration()->getSearchableAttributes();
        if ($searchableAttributes === ['*']) {
            return [];
        }

        // Assign linear weight to each attribute that is searchable
        // ['title', 'summary', 'body] → ['title' => 3, 'summary' => 2, 'body' => 1]
        return array_combine($searchableAttributes, range(count($searchableAttributes), 1, -1));
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
            // Account for old format without attribute: [1,2,3;4,5] → [[1,null],[2,null],[3,null];[4,null],[5,null]]
            $positions = array_map(fn ($position) => !\is_array($position) ? [$position, null] : $position, $positions);

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
            3, // 1st: Number of query terms that match in a document
            2, // 2nd: Proximity of the words
            1, // 3rd: Weight of attributes matched
        ];

        $relevanceFactors = [
            // 1st: Number of query terms that match in a document
            self::calculateMatchCountFactor($positionsPerTerm, $totalQueryTokenCount),

            // 2nd: Proximity of the words
            self::calculateProximityFactor($positionsPerTerm),

            // 3rd: Weight of attributes matched
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
     * "title:0;summary:1" -> ["title" => 0, "summary" => 1]
     *
     * @return array<string, int>
     */
    protected static function parseAttributeWeights(string $attributeWeights): array
    {
        return array_reduce(
            array_filter(explode(';', $attributeWeights)),
            function ($result, $item) {
                [$key, $value] = explode(':', $item);
                return array_merge($result, [
                    $key => (int) $value,
                ]);
            },
            []
        );
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
