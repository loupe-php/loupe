<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class Relevance extends AbstractSorter
{
    public const RELEVANCE_ALIAS = '_relevance';

    public function __construct(
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        if ($searcher->getTokens()->empty() || !isset($searcher->getCTEs()[Searcher::CTE_TERM_DOCUMENT_MATCHES])) {
            return;
        }

        /**
         * First argument is the Query ID of this search request. This is used to cache cosine vectors as they are the
         * same for every result row that is evaluated.
         * Second argument is a comma-separated list of IDF values for the term matches (which is then turned into TF-IDF
         * which can be cached for every row).
         * Third argument is a comma-separated list of TF-IDF values for the document<->term matches.
         */
        $select = sprintf(
            'loupe_relevance(
                    %s,
                    (SELECT group_concat(idf) FROM %s),
                    (SELECT group_concat(tfidf) FROM %s WHERE %s.id=document)
            ) AS %s',
            $searcher->getQueryBuilder()->createNamedParameter($searcher->getQueryId()),
            Searcher::CTE_TERM_MATCHES,
            Searcher::CTE_TERM_DOCUMENT_MATCHES,
            $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
            self::RELEVANCE_ALIAS,
        );

        $searcher->getQueryBuilder()->addSelect($select);

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of our internal null or empty
        // value
        $searcher->getQueryBuilder()->addOrderBy(self::RELEVANCE_ALIAS, $this->direction->getSQL());
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): AbstractSorter
    {
        return new self($direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return $value === self::RELEVANCE_ALIAS;
    }
}
