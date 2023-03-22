<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Index\IndexInfo;

class Simple extends AbstractSorter
{
    public function __construct(
        private string $attributeName,
        private Direction $direction
    ) {
    }

    public function apply(QueryBuilder $queryBuilder, Engine $engine): void
    {
        $queryBuilder->addOrderBy(
            $engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.' . $this->attributeName,
            $this->direction->getSQL()
        );
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        return new self($value, $direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return in_array($value, $engine->getConfiguration()->getValue('sortableAttributes'), true);
    }
}
