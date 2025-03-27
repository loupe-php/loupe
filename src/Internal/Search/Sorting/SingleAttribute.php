<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class SingleAttribute extends AbstractSorter
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

        $qb = $engine->getConnection()->createQueryBuilder();
        $qb
            ->select(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.id AS document_id',
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.' . $attribute . ' AS sort_order'
            )
            ->from(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
            )
            ->innerJoin(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                Searcher::CTE_MATCHES,
                Searcher::CTE_MATCHES,
                sprintf(
                    '%s.document_id = %s.id',
                    Searcher::CTE_MATCHES,
                    $engine->getIndexInfo()->getAliasForTable(
                        IndexInfo::TABLE_NAME_DOCUMENTS
                    )
                )
            )
            ->groupBy('document_id');

        $cteName = 'order_' . $this->attributeName;

        $this->addAndOrderByCte($searcher, $engine, $this->direction, $cteName, $qb);
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
