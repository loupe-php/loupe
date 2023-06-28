<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class Simple extends AbstractSorter
{
    public function __construct(
        private string $attributeName,
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        $searcher->getQueryBuilder()->addOrderBy(
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
        return in_array($value, $engine->getConfiguration()->getSortableAttributes(), true);
    }
}
