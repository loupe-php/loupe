<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Search\AbstractQueryParameters;
use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use Loupe\Loupe\Internal\Search\Ranking\Exactness;
use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use Loupe\Loupe\Internal\Search\Ranking\TypoCount;
use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\SearchParameters;

class Relevance extends AbstractSorter
{
    public const RANKERS = [
        'words' => WordCount::class,
        'typo' => TypoCount::class,
        'proximity' => Proximity::class,
        'attribute' => AttributeWeight::class,
        'exactness' => Exactness::class,
    ];

    public function __construct(private readonly Direction $direction)
    {
    }

    /**
     * @param Searcher<AbstractQueryParameters> $searcher
     */
    public function apply(Searcher $searcher, Engine $engine): void
    {
        $queryParameters = $searcher->getQueryParameters();

        $tokens = $searcher->getSearchTokens()->all();

        if (!\count($tokens) || !$queryParameters instanceof SearchParameters) {
            return;
        }

        $relevances = [];

        foreach ($tokens as $token) {
            $cteName = $searcher->addTermDocumentAggregateCTE($token);

            // Could be that the token is not being searched for, as it might be a stop word
            if (null !== $cteName) {
                $relevances[] = 'COALESCE('.$cteName.".relevance, '0')";
            }
        }

        if ([] === $relevances) {
            return;
        }

        // Searchable attributes determine attribute weight
        $searcher->getQueryBuilder()->addSelect(\sprintf(
            "loupe_relevance('%s', '%s', %s) AS %s",
            implode(':', $engine->getConfiguration()->getSearchableAttributes()),
            implode(':', $engine->getConfiguration()->getRankingRules()),
            implode(" || ';' || ", $relevances),
            Searcher::RELEVANCE_ALIAS,
        ));

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of
        // our internal null or empty value
        $searcher->addOrderBy(Searcher::RELEVANCE_ALIAS, $this->direction->getSQL());

        // Apply threshold
        $threshold = $queryParameters->getRankingScoreThreshold();

        if ($threshold > 0) {
            $searcher->getQueryBuilder()->andWhere(Searcher::RELEVANCE_ALIAS.'>= '.$threshold);
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

    public function requiresFullResultCount(AbstractQueryParameters $queryParameters): bool
    {
        return $queryParameters instanceof SearchParameters
            && $queryParameters->getRankingScoreThreshold() > 0;
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return Searcher::RELEVANCE_ALIAS === $value;
    }

    /**
     * @param array<string> $rules
     *
     * @return array<array{string, float}>
     */
    protected static function getRankers(array $rules): array
    {
        return array_map(
            static function ($rule, $index) {
                $class = self::RANKERS[$rule];
                $weight = Configuration::RANKING_RULES_ORDER_FACTOR ** $index;

                return [$class, $weight];
            },
            $rules,
            range(0, \count($rules) - 1),
        );
    }
}
