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
        private string|QueryBuilder $query,
        private array $tags = [],
        private ?bool $materialized = null,
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

    public function getQuerySql(): string
    {
        return $this->query instanceof QueryBuilder ? $this->query->getSQL() : $this->query;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * null = let SQLite decide, true = force MATERIALIZED, false = force NOT MATERIALIZED
     */
    public function isMaterialized(): ?bool
    {
        return $this->materialized;
    }
}
