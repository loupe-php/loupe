<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;

class Cte
{
    /**
     * @param array<string> $columnAliasList
     * @param array<string> $tags
^     */
    public function __construct(
        private string $name,
        private array $columnAliasList,
        private QueryBuilder $queryBuilder,
        private array $tags = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function getColumnAliasList(): array
    {
        return $this->columnAliasList;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
