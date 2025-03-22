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
        $havingStatement = [];

        $this->handleFilterAstNode($this->searcher->getFilterAst()->getRoot(), $havingStatement);

        return $this->globalQueryBuilder->andHaving(implode(' ', $havingStatement));
    }

    /**
     * @return array<float|string>
     */
    public function buildForMultiAttribute(string $attribute): array
    {
        $this->multiAttributeName = $attribute;

        $havingStatement = [];
        $this->handleFilterAstNode($this->searcher->getFilterAst()->getRoot(), $havingStatement);

        return $havingStatement;
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
     * @param array<string|float> $havingStatement
     */
    private function handleFilterAstNode(Node $node, array &$havingStatement): void
    {
        $documentAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        if ($node instanceof Group) {
            $groupWhere = [];
            foreach ($node->getChildren() as $child) {
                $this->handleFilterAstNode($child, $groupWhere);
            }

            if ($groupWhere !== []) {
                $havingStatement[] = '(';
                $havingStatement[] = implode(' ', $groupWhere);
                $havingStatement[] = ')';
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
                $havingStatement[] = $operator->isNegative() ? '1 = 1' : '1 = 0';
            } elseif (\in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                // Multi attribute
                $this->searcher->addJoinForMultiAttributes();
                $column = (LoupeTypes::isFloatType(LoupeTypes::getTypeFromValue($node->value)) ? 'numeric_value' : 'string_value');
                $withSum = $this->multiAttributeName === null;

                if ($node->operator->isNegative()) {
                    $havingStatement[] = sprintf(
                        $withSum ? 'SUM(CASE WHEN %s THEN 1 ELSE 0 END) = 0' : 'CASE WHEN %s THEN 1 ELSE 0 END',
                        sprintf(
                            '%s.attribute = %s AND %s',
                            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                            $this->globalQueryBuilder->createNamedParameter($node->attribute),
                            $node->operator->opposite()->buildSql(
                                $this->engine->getConnection(),
                                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' . $column,
                                $node->value
                            )
                        )
                    );
                } else {
                    $havingStatement[] = sprintf(
                        $withSum ? 'SUM(CASE WHEN %s THEN 1 ELSE 0 END) > 0' : 'CASE WHEN %s THEN 1 ELSE 0 END',
                        sprintf(
                            '%s.attribute = %s AND %s',
                            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                            $this->globalQueryBuilder->createNamedParameter($node->attribute),
                            $node->operator->buildSql(
                                $this->engine->getConnection(),
                                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' . $column,
                                $node->value
                            )
                        )
                    );
                }
            } else {
                // Single attribute
                $attribute = $node->attribute;

                if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                    $attribute = 'user_id';
                }

                $havingStatement[] = $operator->buildSql(
                    $this->engine->getConnection(),
                    $documentAlias . '.' . $attribute,
                    $node->value
                );
            }
        }

        if ($node instanceof GeoDistance) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $havingStatement[] = '1 = 0';
                return;
            }

            // Add the distance to the select query, so it's also part of the result
            $distanceSelectAlias = $this->searcher->addGeoDistanceSelectToQueryBuilder($node->attributeName, $node->lat, $node->lng);

            // Start a group
            $havingStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            // We use floor() and ceil() respectively to ensure we get matches as the BearingSpherical calculation of the
            // BBOX may not be as precise so when searching for the e.g. 3rd decimal floating point, we might exclude
            // locations we shouldn't.
            $bounds = $node->getBbox();

            $havingStatement = [...$havingStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $havingStatement[] = 'AND';
            $havingStatement[] = $distanceSelectAlias;
            $havingStatement[] = '<=';
            $havingStatement[] = $node->distance;

            // End group
            $havingStatement[] = ')';
        }

        if ($node instanceof GeoBoundingBox) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $havingStatement[] = '1 = 0';
                return;
            }

            // Start a group GeoDistance BBOX
            $havingStatement[] = '(';

            // Same like above for
            $bounds = $node->getBbox();

            $havingStatement = [...$havingStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // End group
            $havingStatement[] = ')';
        }

        if ($node instanceof Concatenator) {
            $havingStatement[] = $node->getConcatenator();
        }
    }
}
