<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;

class Cte
{
    /**
     * @param array<string> $columnAliasList
^     */
    public function __construct(
        private array $columnAliasList,
        private QueryBuilder $queryBuilder
    ) {
    }

    /**
     * @return string[]
     */
    public function getColumnAliasList(): array
    {
        return $this->columnAliasList;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }
}
