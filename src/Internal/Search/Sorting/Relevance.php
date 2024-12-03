<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use Loupe\Loupe\Internal\Search\Searcher;

class Relevance extends AbstractSorter
{
    /**
     * @var array<string, array<string, float>>
     */
    protected static array $attributeWeightValuesCache = [];

    /**
     * @var array<AbstractRanker>
     */
    protected static ?array $rankers = null;

    private const RANKERS = [
        'words' => WordCount::class,
        // 'typo' => TypoCount::class, // Not implemented yet
        'proximity' => Proximity::class,
        'attribute' => AttributeWeight::class,
        // 'exactness' => Exactness::class, // Not implemented yet
    ];

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

        $searchableAttributes = $engine->getConfiguration()->getSearchableAttributes();

        // Check ranking rules at beginning to throw early
        $rankingRules = $engine->getConfiguration()->getRankingRules();
        self::checkRules($rankingRules);

        $select = sprintf(
            "loupe_relevance(json_array('%s'), json_array('%s'), %d, (SELECT group_concat(%s, ';') FROM (%s))) AS %s",
            implode("','", $rankingRules),
            implode("','", $searchableAttributes),
            $searcher->getTokens()->count(),
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
     * @param string $positionsInDocumentPerTerm A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQuery(string $rankingRules, string $searchableAttributes, string $totalQueryTokenCount, string $positionsInDocumentPerTerm): float
    {
        $rankingRules = json_decode($rankingRules, true);
        static::$rankers ??= static::getRankers($rankingRules);

        $searchableAttributes = json_decode($searchableAttributes, true);

        $totalQueryTokenCount = (int) $totalQueryTokenCount;
        $positionsPerTerm = static::parseTermPositions($positionsInDocumentPerTerm);

        $weights = array_map(
            function ($ranker) use ($searchableAttributes, $totalQueryTokenCount, $positionsPerTerm) {
                [$class, $weight] = $ranker;
                return $class::calculate($searchableAttributes, $totalQueryTokenCount, $positionsPerTerm) * $weight;
            },
            static::$rankers
        );

        return array_sum($weights) / count($weights);
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
     * Parse an intermediate string representation of term positions and matches attributes
     *
     * "3:title,8:title,10:title;0;4:summary" -> [[3, "title"], [8, "title"], [10, "title"]], [[0, null]], [[4, "summary"]]
     *
     * @return array<int, array<int, array{int, string|null}>>
     */
    private static function parseTermPositions(string $positionsInDocumentPerTerm): array
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

    /**
     * @param array<string> $rules
     */
    private static function checkRules(array $rules): void
    {
        if (!\count($rules)) {
            throw new InvalidConfigurationException('Ranking rules cannot be empty.');
        }

        foreach ($rules as $v) {
            if (!\is_string($v)) {
                throw new InvalidConfigurationException('Ranking rules must be an array of strings.');
            }
            if (!\in_array($v, array_keys(self::RANKERS), true)) {
                throw new InvalidConfigurationException('Unknown ranking rule: ' . $v);
            }
        }
    }

    /**
     * @param array<string> $rules
     * @return array<int, array<string, float>>
     */
    private static function getRankers(array $rules): array
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
