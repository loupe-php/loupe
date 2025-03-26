<?php

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;

class Cte
{
    public function __construct(private array $columnAliasList, private QueryBuilder $queryBuilder)
    {

    }

    public function getColumnAliasList(): array
    {
        return $this->columnAliasList;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function addSelectWithCteAlias(string $select, string $cteColumnAlias): self
    {
        $this->queryBuilder->addSelect($select);
        $this->columnAliasList[] = $cteColumnAlias;

        return $this;
    }
}