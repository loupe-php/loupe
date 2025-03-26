<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\FilterBuilder\FilterBuilder;
use Loupe\Loupe\Internal\Search\Searcher;

/**
 * Sorts based on a multi attribute (an array value). Currently supporting min() and max() aggregators.
 *
 * We have to apply the filters, also. Otherwise, imagine you have two documents with
 * Document A: ['numbers' => [2, 3, 4, 5]]
 * Document B: ['numbers' => [1, 3, 4, 5]]
 *
 * If you now sort for "min(numbers):asc" and also apply a filter with "numbers >= 2 AND numbers <= 4" (for which
 * both match), you would get document B listed before document A. Because document B's number 1 is lower than document
 * A's number 2. However, our filter said we're only interested in numbers between 2 and 4 so document A must be
 * listed before document B as the other numbers are not even relevant for us (facets of the current search result).
 */
class MultiAttribute extends AbstractSorter
{
    private const MULTI_RGXP = '^(max|min)\((' . Configuration::ATTRIBUTE_NAME_RGXP . ')\)$';

    public function __construct(
        private string $attributeName,
        private Aggregate $aggregate,
        private Direction $direction
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        $isFloatType = LoupeTypes::isFloatType($engine->getIndexInfo()->getLoupeTypeForAttribute($this->attributeName));
        $column = $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' . ($isFloatType ? 'numeric_value' : 'string_value');

        $qb = $engine->getConnection()->createQueryBuilder();
        $qb
            ->addSelect(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS) . '.document AS document_id',
                $this->aggregate->buildSql($column) . 'AS sort_order'
            )
            ->from(
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
            )
            ->innerJoin(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute=%s AND %s.id = %s.attribute',
                    $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $searcher->getQueryBuilder()->createNamedParameter($this->attributeName),
                    $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
            ->innerJoin(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                Searcher::CTE_MATCHES,
                Searcher::CTE_MATCHES,
                sprintf('%s.document_id = %s.document',
                    Searcher::CTE_MATCHES,
                    $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS
                    )
                )
            )
            ->groupBy('document_id');
        ;

        $cteName = 'order_' . $this->attributeName;
        $searcher->addCTE($cteName, new Cte(['document_id', 'sort_order'], $qb));

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

        $searcher->getQueryBuilder()->addOrderBy($cteName . '.sort_order', $this->direction->getSQL());
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        $matches = self::split($value);

        if ($matches === null) {
            throw new \InvalidArgumentException('Invalid string, call supports() first.');
        }

        return new self($matches['attribute'], $matches['aggregate'], $direction);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        $matches = self::split($value);

        if ($matches === null) {
            return false;
        }

        $attribute = $matches['attribute'];

        // We support if it's configured sortable and a multi type
        if (!\in_array($attribute, $engine->getConfiguration()->getSortableAttributes(), true) || !LoupeTypes::isMultiType($engine->getIndexInfo()->getLoupeTypeForAttribute($attribute))) {
            return false;
        }

        return true;
    }

    /**
     * @return null|array{aggregate: Aggregate, attribute: string}
     */
    private static function split(string $value): ?array
    {
        $supports = preg_match('@' . self::MULTI_RGXP . '@', $value, $matches);

        if (!$supports) {
            return null;
        }

        $aggregate = Aggregate::tryFromCaseInsensitive((string) $matches[1]);

        if ($aggregate === null) {
            return null;
        }

        return [
            'aggregate' => $aggregate,
            'attribute' => (string) $matches[2],
        ];
    }
}
