<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\AbstractQueryParameters;
use Loupe\Loupe\Internal\Search\Searcher;

class SingleAttribute extends AbstractSorter
{
    public function __construct(
        private readonly string $attributeName,
        private readonly Direction $direction,
    ) {
    }

    /**
     * @param Searcher<AbstractQueryParameters> $searcher
     */
    public function apply(Searcher $searcher, Engine $engine): void
    {
        $attribute = $this->attributeName;

        // We ignore if it's configured sortable (see supports()) but is not yet part of our document schema
        if (!\in_array($attribute, $engine->getIndexInfo()->getSortableAttributes(), true)) {
            return;
        }

        if ($attribute === $engine->getConfiguration()->getPrimaryKey()) {
            $attribute = '_user_id';
        }

        $documentsAlias = $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $this->addOrderByExpression($searcher, $engine, $this->direction, $documentsAlias.'.'.$attribute);
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        return new self($value, $direction);
    }

    public function requiresFullResultCount(AbstractQueryParameters $queryParameters): bool
    {
        return false;
    }

    public static function supports(string $value, Engine $engine): bool
    {
        // We support if it's configured sortable
        return \in_array($value, $engine->getConfiguration()->getSortableAttributes(), true);
    }
}
