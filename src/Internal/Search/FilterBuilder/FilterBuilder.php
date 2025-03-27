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
use Loupe\Loupe\Internal\Search\Cte;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\Search\Sorting\Aggregate;

class FilterBuilder
{
    private QueryBuilder $globalQueryBuilder;

    public function __construct(
        private Engine $engine,
        private Searcher $searcher,
    ) {
        $this->globalQueryBuilder = $this->searcher->getQueryBuilder();
    }

    public function buildFrom(): string
    {
        $froms = [];

        $this->handleFilterAstNode($this->searcher->getFilterAst()->getRoot(), $froms);

        return implode(' ', $froms);
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
                $froms[] = '(';
                $froms[] = implode(' ', $groupFroms);
                $froms[] = ')';
            }
        }

        if ($node instanceof Filter) {
            $operator = $node->operator;

            // Not existing attributes need be handled as no match if positive and as match if negative
            if (!\in_array($node->attribute, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $froms[] = $operator->isNegative() ? '1 = 1' : '1 = 0';
            } elseif (\in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                $qb = $this->engine->getConnection()->createQueryBuilder();
                $qb->select('d.id AS document_id', 'd.document', 'ma.attribute', 'ma.numeric_value');// TODO
                $qb->from(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS));
                $qb->innerJoin(
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    sprintf(
                        '%s.attribute=%s AND %s.id = %s.attribute',
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                        $this->globalQueryBuilder->createNamedParameter($node->attribute),
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

                $isFloatType = LoupeTypes::isFloatType(LoupeTypes::getTypeFromValue($node->value));

                $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
                    ($isFloatType ? 'numeric_value' : 'string_value');

                $sql = $node->operator->isNegative() ?
                    $node->operator->opposite()->buildSql($this->engine->getConnection(), $column, $node->value) :
                    $node->operator->buildSql($this->engine->getConnection(), $column, $node->value);

                $qb->andWhere($sql);

                $cteName = $this->addCTEForNode($node, $qb, ['attribute', 'numeric_value']); // TODO
                $froms[] = 'SELECT * FROM ' . $cteName;
/*
                $whereStatement[] = sprintf($documentAlias . '.id %s (', $operator->isNegative() ? 'NOT IN' : 'IN');
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';*/
            } else {
                // Single attribute
                $attribute = $node->attribute;

                if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                    $attribute = 'user_id';
                }

                $qb = $this->engine->getConnection()->createQueryBuilder();
                $qb->select('id AS document_id', 'document');
                $qb->from('documents'); // TODO
                $qb->where($operator->buildSql(
                    $this->engine->getConnection(),
                    $documentAlias . '.' . $attribute,
                    $node->value
                ));

                $cteName = $this->addCTEForNode($node, $qb);
                $froms[] = 'SELECT * FROM ' . $cteName;

                /*
                                SELECT id AS document_id, document
                  FROM documents
                  WHERE age > 42

                                $whereStatement[] = $operator->buildSql(
                                    $this->engine->getConnection(),
                                    $documentAlias . '.' . $attribute,
                                    $node->value
                                );*/
            }
        }

        if ($node instanceof GeoDistance) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $froms[] = '1 = 0';
                return;
            }

            // Add the distance to the select query, so it's also part of the result
            $distanceSelectAlias = $this->searcher->addGeoDistanceSelectToQueryBuilder($node->attributeName, $node->lat, $node->lng);

            // Start a group
            $froms[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            // We use floor() and ceil() respectively to ensure we get matches as the BearingSpherical calculation of the
            // BBOX may not be as precise so when searching for the e.g. 3rd decimal floating point, we might exclude
            // locations we shouldn't.
            $bounds = $node->getBbox();

            $froms = [...$froms, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $froms[] = 'AND';
            $froms[] = $distanceSelectAlias;
            $froms[] = '<=';
            $froms[] = $node->distance;

            // End group
            $froms[] = ')';
        }

        if ($node instanceof GeoBoundingBox) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $froms[] = '1 = 0';
                return;
            }

            // Start a group GeoDistance BBOX
            $froms[] = '(';

            // Same like above for
            $bounds = $node->getBbox();

            $froms = [...$froms, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // End group
            $froms[] = ')';
        }

        if ($node instanceof Concatenator) {
            $froms[] = $node->getSetOperator();
        }
    }

    private function addCTEForNode(Node $node, QueryBuilder $qb, array $additionalAliases = []): string
    {
        $columnAliases = array_merge(['document_id', 'document'], $additionalAliases); // always must start with document_id and document
        $cteName = 'filtered_node_' . $this->searcher->getFilterAst()->getIdForNode($node);
        $this->searcher->addCTE($cteName, new Cte($columnAliases, $qb));

        return $cteName;
    }
}
