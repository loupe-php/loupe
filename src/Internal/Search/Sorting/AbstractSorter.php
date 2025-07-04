<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\FilterValue;
use Loupe\Loupe\Internal\Filter\Ast\Operator;
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\Searcher;

abstract class AbstractSorter
{
    private int $id = 0;

    abstract public function apply(Searcher $searcher, Engine $engine): void;

    abstract public static function fromString(string $value, Engine $engine, Direction $direction): self;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    abstract public static function supports(string $value, Engine $engine): bool;

    protected function addAndOrderByCte(Searcher $searcher, Engine $engine, Direction $direction, string $cteName, QueryBuilder $queryBuilder): void
    {
        if ($searcher->hasCTE($cteName)) {
            return;
        }

        $searcher->addCTE(new Cte($cteName, ['document_id', 'sort_order'], $queryBuilder));

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
        // are ordered ascending first.
        // Null and empty values should always come last for Loupe (or generally speaking for any search engine probably).
        $searcher->getQueryBuilder()->addOrderBy(
            Operator::Equals->buildSql(
                $engine->getConnection(),
                $alias,
                FilterValue::createNull()
            ),
            Direction::ASC->getSQL()
        );

        $searcher->getQueryBuilder()->addOrderBy(
            Operator::Equals->buildSql(
                $engine->getConnection(),
                $alias,
                FilterValue::createEmpty()
            ),
            Direction::ASC->getSQL()
        );

        $searcher->getQueryBuilder()->addOrderBy($alias, $direction->getSQL());
    }
}
