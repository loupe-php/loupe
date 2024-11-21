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
                "SELECT (SELECT COALESCE(group_concat(DISTINCT position), '0') FROM %s WHERE %s.id=document) AS %s",
                $searcher->getCTENameForToken(Searcher::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token),
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                Searcher::RELEVANCE_ALIAS . '_per_term',
            );
        }

        if ($positionsPerDocument === []) {
            return;
        }

        $select = sprintf(
            "loupe_relevance((SELECT group_concat(%s, ';') FROM (%s)), %s) AS %s",
            Searcher::RELEVANCE_ALIAS . '_per_term',
            implode(' UNION ALL ', $positionsPerDocument),
            $searcher->getTokens()->count(),
            Searcher::RELEVANCE_ALIAS,
        );

        $searcher->getQueryBuilder()->addSelect($select);

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of our internal null or empty
        // value
        $searcher->getQueryBuilder()->addOrderBy(Searcher::RELEVANCE_ALIAS, $this->direction->getSQL());

        // Apply threshold
        if ($searcher->getSearchParameters()->getRankingScoreThreshold() > 0) {
            $searcher->getQueryBuilder()->andWhere(Searcher::RELEVANCE_ALIAS . '>= ' . $searcher->getSearchParameters()->getRankingScoreThreshold());
        }
    }

    /**
     * @param array<int, array<int>> $positionsPerTerm The positions MUST be ordered ASC
     */
    public static function calculateProximityFactor(array $positionsPerTerm, float $decayFactor = 0.1): float
    {
        $allAdjacent = true;
        $totalProximity = 0;
        $totalTermsRelevantForProximity = \count($positionsPerTerm) - 1;
        $positionPrev = null;

        foreach ($positionsPerTerm as $positions) {
            if ($positionPrev === null) {
                $positionPrev = $positions[0];
                continue;
            }

            $distance = 0;

            foreach ($positions as $position) {
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
     * Example: A string with "3,8,10;0;4" would read as follows:
     * - The query consisted of 3 tokens (terms).
     * - The first term matched. At positions 3, 8 and 10.
     * - The second term did not match (position 0).
     * - The third term matched. At position 4
     *
     * @param string $positionsInDocumentPerTerm A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQuery(string $positionsInDocumentPerTerm, string $totalQueryTokenCount): float
    {
        /**
         * 1st: Number of query terms that match in a document.
         * 2nd: Proximity of the words
         */
        static $relevanceWeights = [2, 1]; // Higher weight means more importance
        static $totalWeight = 3; // Must be the sum of the above

        $relevanceFactors = [];
        $totalQueryTokenCount = (int) $totalQueryTokenCount;
        $positionsPerTerm = array_map(fn ($term) => array_map('intval', explode(',', $term)), explode(';', $positionsInDocumentPerTerm));

        // 1st: Number of query terms that match in a document.
        $totalMatchedTokens = \count(array_filter($positionsPerTerm, function ($termArray) {
            return !(\count($termArray) === 1 && $termArray[0] === 0);
        }));
        $relevanceFactors[] = $totalMatchedTokens / $totalQueryTokenCount;

        // 2nd: Proximity
        $relevanceFactors[] = self::calculateProximityFactor($positionsPerTerm);

        // Calculate weighted average
        $totalFactor = 0;
        foreach ($relevanceFactors as $index => $factor) {
            $totalFactor += $factor * $relevanceWeights[$index];
        }

        return $totalFactor /= $totalWeight;
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): AbstractSorter
    {
        return new self($direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return $value === Searcher::RELEVANCE_ALIAS;
    }
}
