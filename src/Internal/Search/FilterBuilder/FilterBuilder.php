<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\FilterBuilder;

use Doctrine\DBAL\Query\QueryBuilder;
use Location\Bounds;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
use Loupe\Loupe\Internal\Filter\Ast\GeoBoundingBox;
use Loupe\Loupe\Internal\Filter\Ast\GeoDistance;
use Loupe\Loupe\Internal\Filter\Ast\Group;
use Loupe\Loupe\Internal\Filter\Ast\Node;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\Search\Sorting\Aggregate;

class FilterBuilder
{
    private ?string $multiAttributeName = null;

    public function __construct(
        private Engine $engine,
        private Searcher $searcher,
        private QueryBuilder $globalQueryBuilder
    ) {
    }

    public function buildForDocument(): QueryBuilder
    {
        $whereStatement = [];

        $this->handleFilterAstNode($this->searcher->getFilterAst()->getRoot(), $whereStatement);

        return $this->globalQueryBuilder->andWhere(implode(' ', $whereStatement));
    }

    public function buildForMultiAttribute(string $attribute, Aggregate $aggregate): QueryBuilder
    {
        $this->multiAttributeName = $attribute;
        $column = $this->getMultiAttributeColumnForAttribute($attribute);

        // Build the subquery, SELECT our aggregate and joining the multi attributes on our attribute name.
        $qb = $this->engine->getConnection()->createQueryBuilder();
        $qb->addSelect($this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS) . '.document AS document_id');
        $qb->addSelect($aggregate->buildSql($column) . ' AS sort_order');
        $this->addMultiAttributeFromAndJoinToQueryBuilder($qb, $attribute);
        $qb->groupBy('document_id');

        $whereStatement = [];
        $this->handleFilterAstNode($this->searcher->getFilterAst()->getRoot(), $whereStatement);

        if ($whereStatement === []) {
            return $qb;
        }

        $qb->andWhere(implode(' ', $whereStatement));

        return $qb;
    }

    private function addMultiAttributeFromAndJoinToQueryBuilder(QueryBuilder $queryBuilder, string $attributeName): void
    {
        $queryBuilder->from(
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
        )
            ->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute=%s AND %s.id = %s.attribute',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->globalQueryBuilder->createNamedParameter($attributeName),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            );
    }

    /**
     * @return array<string|float>
     */
    private function createGeoBoundingBoxWhereStatement(string $documentAlias, GeoBoundingBox|GeoDistance $node, Bounds $bounds): array
    {
        $whereStatement = [];

        // Prevent nullable
        $nullTerm = $this->globalQueryBuilder->createNamedParameter(LoupeTypes::VALUE_NULL);
        $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lat';
        $whereStatement[] = '!=';
        $whereStatement[] = $nullTerm;
        $whereStatement[] = 'AND';
        $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lng';
        $whereStatement[] = '!=';
        $whereStatement[] = $nullTerm;

        $whereStatement[] = 'AND';

        // Longitude
        $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lng';
        $whereStatement[] = 'BETWEEN';
        $whereStatement[] = $bounds->getWest();
        $whereStatement[] = 'AND';
        $whereStatement[] = $bounds->getEast();

        $whereStatement[] = 'AND';

        // Latitude
        $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lat';
        $whereStatement[] = 'BETWEEN';
        $whereStatement[] = $bounds->getSouth();
        $whereStatement[] = 'AND';
        $whereStatement[] = $bounds->getNorth();

        return $whereStatement;
    }

    private function createSubQueryForMultiAttribute(Filter $node): string
    {
        $tableAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS);

        if ($this->multiAttributeName) {
            $select = $tableAlias . '.attribute';
        } else {
            $select = $tableAlias . '.document';
        }

        $qb = $this->engine->getConnection()
            ->createQueryBuilder();
        $qb
            ->select($select)
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
                    $this->globalQueryBuilder->createNamedParameter($node->attribute),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
        ;

        $isFloatType = LoupeTypes::isFloatType(LoupeTypes::getTypeFromValue($node->value));

        $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
            ($isFloatType ? 'numeric_value' : 'string_value');

        $sql = $node->operator->isNegative() ?
            $node->operator->opposite()->buildSql($this->engine->getConnection(), $column, $node->value) :
            $node->operator->buildSql($this->engine->getConnection(), $column, $node->value);

        $qb->andWhere($sql);

        return $qb->getSQL();
    }

    private function getMultiAttributeColumnForAttribute(string $attribute): string
    {
        $isFloatType = LoupeTypes::isFloatType($this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute));
        return $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' . ($isFloatType ? 'numeric_value' : 'string_value');
    }

    /**
     * @param array<string|float> $whereStatement
     */
    private function handleFilterAstNode(Node $node, array &$whereStatement): void
    {
        $documentAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        if ($node instanceof Group) {
            $groupWhere = [];
            foreach ($node->getChildren() as $child) {
                $this->handleFilterAstNode($child, $groupWhere);
            }

            if ($groupWhere !== []) {
                $whereStatement[] = '(';
                $whereStatement[] = implode(' ', $groupWhere);
                $whereStatement[] = ')';
            }
        }

        if ($node instanceof Filter) {
            // Ignore if not in question
            if ($this->multiAttributeName && $this->multiAttributeName !== $node->attribute) {
                return;
            }

            $operator = $node->operator;

            // Not existing attributes need be handled as no match if positive and as match if negative
            if (!\in_array($node->attribute, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $whereStatement[] = $operator->isNegative() ? '1 = 1' : '1 = 0';
            } elseif (\in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                // Multi attribute
                if ($this->multiAttributeName) {
                    $multiAttributeAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES);
                    $whereStatement[] = sprintf($multiAttributeAlias . '.id %s (', $operator->isNegative() ? 'NOT IN' : 'IN');
                } else {
                    $whereStatement[] = sprintf($documentAlias . '.id %s (', $operator->isNegative() ? 'NOT IN' : 'IN');
                }

                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';
            } else {
                // Single attribute
                $attribute = $node->attribute;

                if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                    $attribute = 'user_id';
                }

                $whereStatement[] = $operator->buildSql(
                    $this->engine->getConnection(),
                    $documentAlias . '.' . $attribute,
                    $node->value
                );
            }
        }

        if ($node instanceof GeoDistance) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $whereStatement[] = '1 = 0';
                return;
            }

            // Add the distance to the select query, so it's also part of the result
            $distanceSelectAlias = $this->searcher->addGeoDistanceSelectToQueryBuilder($node->attributeName, $node->lat, $node->lng);

            // Start a group
            $whereStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            // We use floor() and ceil() respectively to ensure we get matches as the BearingSpherical calculation of the
            // BBOX may not be as precise so when searching for the e.g. 3rd decimal floating point, we might exclude
            // locations we shouldn't.
            $bounds = $node->getBbox();

            $whereStatement = [...$whereStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $whereStatement[] = 'AND';
            $whereStatement[] = $distanceSelectAlias;
            $whereStatement[] = '<=';
            $whereStatement[] = $node->distance;

            // End group
            $whereStatement[] = ')';
        }

        if ($node instanceof GeoBoundingBox) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $whereStatement[] = '1 = 0';
                return;
            }

            // Start a group GeoDistance BBOX
            $whereStatement[] = '(';

            // Same like above for
            $bounds = $node->getBbox();

            $whereStatement = [...$whereStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // End group
            $whereStatement[] = ')';
        }

        if ($node instanceof Concatenator) {
            $whereStatement[] = $node->getConcatenator();
        }
    }
}
