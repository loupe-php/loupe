<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Operator;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\Searcher;

abstract class AbstractSorter
{
    abstract public function apply(Searcher $searcher, Engine $engine): void;

    abstract public static function fromString(string $value, Engine $engine, Direction $direction): self;

    abstract public static function supports(string $value, Engine $engine): bool;

    protected function addAndOrderByCte(Searcher $searcher, Engine $engine, Direction $direction, string $cteName, QueryBuilder $queryBuilder): void
    {
        if ($searcher->hasCTE($cteName)) {
            return;
        }

        $searcher->addCTE($cteName, new Cte(['document_id', 'sort_order'], $queryBuilder));

        $searcher->getQueryBuilder()
            ->innerJoin(
                Searcher::CTE_MATCHES,
                $cteName,
                $cteName,
                sprintf(
                    '%s.document_id = %s.document_id',
                    $cteName,
                    Searcher::CTE_MATCHES
                )
            );

        $alias = $cteName . '.sort_order';

        // Because of how Loupe works (SQLite's loosely typed system) we need to always ensure that null and empty values
        // are ordered (in the opposite way) first, so that they then match with the regular sorting
        $searcher->getQueryBuilder()->addOrderBy(
            Operator::Equals->buildSql(
                $engine->getConnection(),
                $alias,
                LoupeTypes::VALUE_NULL
            ),
            $direction->opposite()->getSQL()
        );

        $searcher->getQueryBuilder()->addOrderBy(
            Operator::Equals->buildSql(
                $engine->getConnection(),
                $alias,
                LoupeTypes::VALUE_EMPTY
            ),
            $direction->opposite()->getSQL()
        );

        $searcher->getQueryBuilder()->addOrderBy($alias, $direction->getSQL());
    }
}
