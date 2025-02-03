<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use Loupe\Loupe\Internal\Search\Ranking\Exactness;
use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use Loupe\Loupe\Internal\Search\Ranking\TypoCount;
use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use Loupe\Loupe\Internal\Search\Searcher;

class Relevance extends AbstractSorter
{
    public const RANKERS = [
        'words' => WordCount::class,
        'typo' => TypoCount::class,
        'proximity' => Proximity::class,
        'attribute' => AttributeWeight::class,
        'exactness' => Exactness::class,
    ];

    public function __construct(
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        $tokens = $searcher->getTokens()->all();
        if (!\count($tokens)) {
            return;
        }

        $positionsPerDocument = [];

        foreach ($tokens as $token) {
            $cteName = $searcher->getCTENameForToken(Searcher::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);

            // Could be that the token is not being searched for, as it might be a stop word
            if (!$searcher->hasCTE($cteName)) {
                continue;
            }

            // COALESCE() makes sure that if the token does not match a document, we don't have NULL but a 0 which is important
            // for the relevance split. Otherwise, the relevance calculation cannot know which of the documents did not match
            // because it's just a ";" separated list.
            $positionsPerDocument[] = sprintf(
                "SELECT (SELECT COALESCE(group_concat(DISTINCT position || ':' || attribute || ':' || typos), '0') FROM %s WHERE %s.id=document) AS %s",
                $cteName,
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                Searcher::RELEVANCE_ALIAS . '_per_term',
            );
        }

        // Searchable attributes to determine attribute weight
        $searchableAttributes = $engine->getConfiguration()->getSearchableAttributes();

        $select = sprintf(
            "loupe_relevance(
                '%s', '%s',
                (SELECT group_concat(%s, ';') FROM (%s))
            ) AS %s",
            implode(':', $searchableAttributes),
            implode(':', $engine->getConfiguration()->getRankingRules()),
            Searcher::RELEVANCE_ALIAS . '_per_term',
            implode(' UNION ALL ', $positionsPerDocument),
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
     * Example: A string with "3:title,8:title,10:title;0;4:summary" would read as follows:
     * - The query consisted of 3 tokens (terms).
     * - The first term matched. At positions 3, 8 and 10 in the `title` attribute.
     * - The second term did not match (position 0).
     * - The third term matched. At position 4 in the `summary` attribute.
     *
     * @param string $termPositions A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQuery(string $searchableAttributes, string $rankingRules, string $termPositions): float
    {
        $rankingInfo = RankingInfo::fromQueryFunction($searchableAttributes, $rankingRules, $termPositions);
        $rankers = static::getRankers($rankingInfo->getRankingRules());

        $weights = [];
        $totalWeight = 0;
        foreach ($rankers as [$class, $weight]) {
            $weights[] = $class::calculate($rankingInfo) * $weight;
            $totalWeight += $weight;
        }

        return array_sum($weights) / $totalWeight;
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
     * @param array<string> $rules
     * @return array<array{string, float}>
     */
    protected static function getRankers(array $rules): array
    {
        return array_map(
            function ($rule, $index) {
                $class = self::RANKERS[$rule];
                $weight = Configuration::RANKING_RULES_ORDER_FACTOR ** $index;
                return [$class, $weight];
            },
            $rules,
            range(0, \count($rules) - 1)
        );
    }
}
