<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
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
        $isFloatType = $engine->getIndexInfo()->isNumericAttribute($this->attributeName);
        $column = ($isFloatType ? 'numeric_value' : 'string_value');

        $multiFilterCte = $searcher->addAllMultiFiltersCte($this->attributeName, $this->getFilterSelectAlias());

        // There are filters for this attribute, we have to apply those filters to our multi attribute
        if ($multiFilterCte !== null) {
            $qb = $this->createQueryBuilderForFilterCte($engine, $multiFilterCte);
        } else {
            // Otherwise we join with the general matches
            $qb = $this->createQueryBuilderWithoutFilterCte($engine, $searcher, $column);
        }

        $qb->groupBy('document_id');

        $cteName = 'order_' . $this->attributeName;
        $this->addAndOrderByCte($searcher, $engine, $this->direction, $cteName, $qb);
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        $matches = self::split($value);

        if ($matches === null) {
            throw new \InvalidArgumentException('Invalid string, call supports() first.');
        }

        return new self($matches['attribute'], $matches['aggregate'], $direction);
    }

    public function getAttribute(): string
    {
        return $this->attributeName;
    }

    public function getFilterSelect(Engine $engine): string
    {
        return $this->aggregate->buildSql(
            $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES)
            . '.'
            . $this->getColumnName($engine)
        );
    }

    public function getFilterSelectAlias(): string
    {
        return 'sort_value_' . $this->getId();
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

    private function createQueryBuilderForFilterCte(Engine $engine, string $cteName): QueryBuilder
    {
        $qb = $engine->getConnection()->createQueryBuilder();
        $qb
            ->addSelect(
                sprintf('%s.document_id AS document_id', $cteName),
                sprintf('%s AS sort_order', $this->getFilterSelectAlias()),
            )
            ->from($cteName)
        ;

        return $qb;
    }

    private function createQueryBuilderWithoutFilterCte(Engine $engine, Searcher $searcher, string $column): QueryBuilder
    {
        $qb = $engine->getConnection()->createQueryBuilder()
            ->addSelect(
                $engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS) . '.document AS document_id',
                $this->aggregate->buildSql($column) . ' AS sort_order'
            );

        $searcher->addFromMultiAttributesAndJoinMatches($qb, $this->attributeName);

        return $qb;
    }

    private function getColumnName(Engine $engine): string
    {
        $isFloatType = LoupeTypes::isFloatType($engine->getIndexInfo()->getLoupeTypeForAttribute($this->attributeName));
        return $isFloatType ? 'numeric_value' : 'string_value';
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
