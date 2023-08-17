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
        $attribute = $this->attributeName;

        // We ignore if it's configured sortable (see supports()) but is not yet part of our document schema
        if (!\in_array($attribute, $engine->getIndexInfo()->getSortableAttributes(), true)) {
            return;
        }

        if ($attribute === $engine->getConfiguration()->getPrimaryKey()) {
            $attribute = 'user_id';
        }

        $attribute = $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.' . $attribute;

        $this->addOrderBy($searcher, $engine, $attribute, $this->direction);
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        return new self($value, $direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        // We support if it's configured sortable
        return \in_array($value, $engine->getConfiguration()->getSortableAttributes(), true);
    }
}
