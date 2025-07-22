<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\FilterBuilder;

use Doctrine\DBAL\Query\QueryBuilder;
use Location\Bounds;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Ast;
use Loupe\Loupe\Internal\Filter\Ast\AttributeFilterInterface;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
use Loupe\Loupe\Internal\Filter\Ast\GeoBoundingBox;
use Loupe\Loupe\Internal\Filter\Ast\GeoDistance;
use Loupe\Loupe\Internal\Filter\Ast\Group;
use Loupe\Loupe\Internal\Filter\Ast\Node;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\Search\Sorting;

class FilterBuilder
{
    private const CTE_PREFIX = 'cte_fnode_';

    public function __construct(
        private Engine $engine,
        private Searcher $searcher,
        private Ast $filterAst,
    ) {
    }

    public function buildFrom(): string
    {
        $froms = [];

        /**
         * TODO: This could be optimized.
         * Right now, the filter "multi_attribute = 'foo' AND single_attribute >= 42" is converted to
         * "<cte_1> INTERSECT <cte_2>" for simplicity. It has to remain like this when there are different multi
         * attributes in the same filter group or the query is an disjunctive query (OR/UNION). However, in the example above
         * (1 multi attribute, or only single attributes and conjunctive (AND)), the CTE could be inlined to one CTE
         * only which should speed up the filtering.
         */
        $this->handleFilterAstNode($this->filterAst->getRoot(), $froms);

        return implode(' ', $froms);
    }

    /**
     * @return array<string|float>
     */
    public function createGeoBoundingBoxWhereStatement(string $attributeName, Bounds|null $bounds = null): array
    {
        $documentAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $whereStatement = [];

        // Prevent nullable
        $nullTerm = $this->searcher->createNamedParameter(LoupeTypes::VALUE_NULL);
        $whereStatement[] = $documentAlias . '.' . $attributeName . '_geo_lat';
        $whereStatement[] = '!=';
        $whereStatement[] = $nullTerm;
        $whereStatement[] = 'AND';
        $whereStatement[] = $documentAlias . '.' . $attributeName . '_geo_lng';
        $whereStatement[] = '!=';
        $whereStatement[] = $nullTerm;

        if ($bounds === null) {
            return $whereStatement;
        }

        $whereStatement[] = 'AND';

        // Longitude
        $whereStatement[] = $documentAlias . '.' . $attributeName . '_geo_lng';
        $whereStatement[] = 'BETWEEN';
        $whereStatement[] = $bounds->getWest();
        $whereStatement[] = 'AND';
        $whereStatement[] = $bounds->getEast();

        $whereStatement[] = 'AND';

        // Latitude
        $whereStatement[] = $documentAlias . '.' . $attributeName . '_geo_lat';
        $whereStatement[] = 'BETWEEN';
        $whereStatement[] = $bounds->getSouth();
        $whereStatement[] = 'AND';
        $whereStatement[] = $bounds->getNorth();

        return $whereStatement;
    }

    /**
     * @param array<string> $additionalAliases
     */
    private function addCTEForNode(Node $node, QueryBuilder $qb, array $additionalAliases = []): string
    {
        $columnAliases = array_merge(['document_id'], $additionalAliases); // always must start with document_id
        $tags = [];

        if ($node instanceof AttributeFilterInterface) {
            $cteName = self::CTE_PREFIX . $node->getShortHash();
            $tags[] = 'attribute:' . $node->getAttribute();
        } else {
            $cteName = self::CTE_PREFIX . $this->filterAst->getIdForNode($node);
        }

        $this->searcher->addCTE(new Cte($cteName, $columnAliases, $qb, $tags));

        return $cteName;
    }

    private function addCTEForSingleAttribute(Node $node, string $where): string
    {
        $qb = $this->createQueryBuilderForSingleAttribute()
            ->where($where);

        return $this->addCTEForNode($node, $qb);
    }

    private function createQueryBuilderForSingleAttribute(): QueryBuilder
    {
        return $this->engine->getConnection()->createQueryBuilder()
            ->select(
                sprintf(
                    '%s.id AS document_id',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
                )
            )
            ->from(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
            );
    }

    private function createSubQueryForMultiAttribute(Filter $node): string
    {
        $qb = $this->engine->getConnection()
            ->createQueryBuilder();
        $qb
            ->select(sprintf('%s.document', $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)))
            ->from(
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
            )
            ->innerJoin(
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute=%s AND %s.id = %s.attribute',
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->searcher->createNamedParameter($node->attribute),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
        ;

        $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
            $node->value->getMultiAttributeColumn();

        $sql = $node->operator->isNegative() ?
            $node->operator->opposite()->buildSql($this->engine->getConnection(), $column, $node->value) :
            $node->operator->buildSql($this->engine->getConnection(), $column, $node->value);

        $qb->andWhere($sql);

        return $qb->getSQL();
    }

    /**
     * @return array<string, string> The SELECT statement and the alias
     */
    private function getSortingSelects(string $attribute): array
    {
        $selects = [];

        foreach ($this->searcher->getSorting()->getSorters() as $sorter) {
            if ($sorter instanceof Sorting\MultiAttribute && $attribute === $sorter->getAttribute()) {
                $selects[$sorter->getFilterSelect($this->engine)] = $sorter->getFilterSelectAlias();
            }
        }

        return $selects;
    }

    /**
     * @param array<string|float> $froms
     */
    private function handleFilterAstNode(Node $node, array &$froms): void
    {
        $documentAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        if ($node instanceof Group) {
            $groupFroms = [];
            foreach ($node->getChildren() as $child) {
                $this->handleFilterAstNode($child, $groupFroms);
            }

            if ($groupFroms !== []) {
                $froms[] = 'SELECT document_id FROM (';
                $froms[] = implode(' ', $groupFroms);
                $froms[] = ')';
            }
        }

        if ($node instanceof Filter) {
            $operator = $node->operator;

            // Not existing attributes need be handled as no match if positive and as match if negative
            if (!\in_array($node->attribute, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                if ($operator->isNegative()) {
                    // If the operator is negative, it means all documents match
                    $froms[] = 'SELECT id AS document_id, document FROM documents';
                } else {
                    // Otherwise, no document matches
                    $froms[] = 'SELECT document_id FROM (SELECT NULL AS document_id) WHERE 1 = 0';
                }
            } elseif ($this->engine->getIndexInfo()->isMultiFilterableAttribute($node->attribute)) {
                $sortingSelects = $this->getSortingSelects($node->attribute);
                $qb = $this->engine->getConnection()->createQueryBuilder();
                $qb->select(sprintf('%s.id AS document_id', $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)));
                foreach ($sortingSelects as $sortingSelect => $alias) {
                    $qb->addSelect($sortingSelect . ' AS ' . $alias);
                }
                $qb->from(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS));
                $qb->innerJoin(
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    sprintf(
                        '%s.attribute=%s AND %s.id = %s.attribute',
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                        $this->searcher->createNamedParameter($node->attribute),
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                    )
                )
                    ->innerJoin(
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                        IndexInfo::TABLE_NAME_DOCUMENTS,
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                        sprintf(
                            '%s.id = %s.document',
                            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                        )
                    );

                $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
                    $node->value->getMultiAttributeColumn();

                // If the multi attribute operator is positive, we can inline the query, otherwise, we need a subquery
                if ($node->operator->isPositive()) {
                    $qb->andWhere($node->operator->buildSql($this->engine->getConnection(), $column, $node->value));
                } else {
                    $whereStatement = [$documentAlias . '.id NOT IN ('];
                    $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                    $whereStatement[] = ')';
                    $qb->andWhere(implode(' ', $whereStatement));
                }

                // Needed in case a multi sorter (that does MIN() or MAX() or any other aggregate) is applied.
                $qb->groupBy('document_id');

                $cteName = $this->addCTEForNode($node, $qb, $sortingSelects);
                $froms[] = 'SELECT document_id FROM ' . $cteName;
            } else {
                // Single attribute
                $attribute = $node->attribute;

                if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                    $attribute = 'user_id';
                }

                $cteName = $this->addCTEForSingleAttribute($node, $operator->buildSql(
                    $this->engine->getConnection(),
                    $documentAlias . '.' . $attribute,
                    $node->value
                ));
                $froms[] = 'SELECT document_id FROM ' . $cteName;
            }
        }

        if ($node instanceof GeoDistance) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $froms[] = 'SELECT document_id FROM (SELECT NULL AS document_id) WHERE 1 = 0';

                return;
            }

            // Add the distance CTE
            $distanceCte = $this->searcher->addGeoDistanceCte(
                $node->attributeName,
                $node->lat,
                $node->lng,
                $node->getBbox()
            );

            $qb = $this->createQueryBuilderForSingleAttribute();
            $qb->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                $distanceCte,
                $distanceCte,
                sprintf(
                    '%s.document_id = %s.id',
                    $distanceCte,
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                )
            );

            $where = [];

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $where[] = $distanceCte . '.distance';
            $where[] = '<=';
            $where[] = $node->distance;

            $qb->andWhere(implode(' ', $where));

            $cteName = $this->addCTEForNode($node, $qb);
            $froms[] = 'SELECT document_id FROM ' . $cteName;
        }

        if ($node instanceof GeoBoundingBox) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $froms[] = 'SELECT document_id FROM (SELECT NULL AS document_id) WHERE 1 = 0';
                return;
            }

            $cteName = $this->addCTEForSingleAttribute(
                $node,
                implode(' ', $this->createGeoBoundingBoxWhereStatement($node->attributeName, $node->getBbox()))
            );
            $froms[] = 'SELECT document_id FROM ' . $cteName;
        }

        if ($node instanceof Concatenator) {
            $froms[] = $node->getSetOperator();
        }
    }
}
