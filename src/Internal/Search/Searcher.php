<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Ast\Ast;
use Terminal42\Loupe\Internal\Filter\Ast\Concatenator;
use Terminal42\Loupe\Internal\Filter\Ast\Filter;
use Terminal42\Loupe\Internal\Filter\Ast\Group;
use Terminal42\Loupe\Internal\Filter\Ast\Node;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Search\Sorting\GeoPoint;
use Terminal42\Loupe\Internal\Util;
use voku\helper\UTF8;

class Searcher
{
    private QueryBuilder $queryBuilder;

    public function __construct(
        private Engine $engine,
        private Parser $filterParser,
        private array $searchParameters
    ) {
        $this->searchParameters = (new Processor())->process(
            $this->getConfigTreeBuilderForSearchParameters()
                ->buildTree(),
            [$this->searchParameters]
        );
    }

    public function fetchResult(): array
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->engine->getConnection()
            ->createQueryBuilder();

        $this->selectTotalHits();
        $this->selectDocuments();
        $this->filterDocuments();
        $this->searchDocuments();
        $this->sortDocuments();
        $this->limitPagination();

        $showAllAttributes = ['*'] === $this->searchParameters['attributesToRetrieve'];
        $attributesToRetrieve = array_flip($this->searchParameters['attributesToRetrieve']);

        $hits = [];
        foreach ($this->queryBuilder->fetchAllAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            if (array_key_exists(GeoPoint::DISTANCE_ALIAS, $result)) {
                $document['_geoDistance'] = (int) round($result[GeoPoint::DISTANCE_ALIAS]);
            }

            $hits[] = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);
        }

        $totalHits = $result['totalHits'] ?? 0;
        $totalPages = (int) ceil($totalHits / $this->searchParameters['hitsPerPage']);
        $end = (int) floor(microtime(true) * 1000);

        return [
            'hits' => $hits,
            'query' => $this->searchParameters['q'],
            'processingTimeMs' => $end - $start,
            'hitsPerPage' => $this->searchParameters['hitsPerPage'],
            'page' => $this->searchParameters['page'],
            'totalPages' => $totalPages,
            'totalHits' => $totalHits,
        ];
    }

    private function createSubQueryForMultiAttribute(Filter $node): string
    {
        $qb = $this->engine->getConnection()
            ->createQueryBuilder();
        $qb
            ->select('document')
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
                    $this->queryBuilder->createNamedParameter($node->attribute),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()
                        ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
        ;

        $column = is_float($node->value) ? 'numeric_value' : 'string_value';

        $qb->andWhere(
            sprintf(
                '%s.%s %s %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                $column,
                $node->operator->value,
                $this->queryBuilder->createNamedParameter($node->value)
            )
        );

        return $qb->getSQL();
    }

    private function createSubQueryForTerm(string $term): string
    {
        $termParameter = $this->queryBuilder->createNamedParameter($term);
        $levenshteinDistance = 1; // TODO

        /*
         * WHERE
         *    term LIKE '<first_char>%'
         *    AND
         *    (
         *      term = '<term>'
         *      OR
         *      (
         *          LENGTH(term) >= <term> - <lev-distance>
         *          AND
         *          LENGTH(term) <= <term> + <lev-distance>
          *         AND
         *          <levmax_levenshtein(<term>, term, <distance>)
         *       )
         *    )
         */
        $where = [];
        $where[] = sprintf(
            '%s.term LIKE %s',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            $this->queryBuilder->createNamedParameter(UTF8::first_char($term) . '%')
        );
        $where[] = 'AND';
        $where[] = '(';
        $where[] = sprintf(
            '%s.term = %s',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            $termParameter
        );
        $where[] = 'OR';
        $where[] = '(';
        $where[] = sprintf(
            '%s.length >= %d',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            UTF8::strlen($term) - 1
        );
        $where[] = 'AND';
        $where[] = sprintf(
            '%s.length <= %d',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            UTF8::strlen($term) + 1
        );
        $where[] = 'AND';
        $where[] = sprintf(
            'max_levenshtein(%s, %s.term, %d)',
            $termParameter,
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            $levenshteinDistance
        );
        $where[] = ')';
        $where[] = ')';

        $termQuery = sprintf(
            'SELECT %s.id FROM %s %s WHERE %s',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            IndexInfo::TABLE_NAME_TERMS,
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            implode(' ', $where)
        );

        return sprintf(
            'SELECT DISTINCT document FROM %s %s WHERE %s.term IN (%s)',
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS),
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS),
            $termQuery
        );
    }

    private function filterDocuments(): void
    {
        /** @var Ast|string $ast */
        $ast = $this->searchParameters['filter'];

        if ($ast === '') {
            return;
        }

        $whereStatement = [];

        foreach ($ast->getNodes() as $node) {
            $this->handleFilterAstNode($node, $whereStatement);
        }

        $this->queryBuilder->andWhere(implode(' ', $whereStatement));
    }

    private function getConfigTreeBuilderForSearchParameters(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('searchParams');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode->children()
            ->scalarNode('q')
            ->defaultValue('')
            ->end()
            ->scalarNode('filter')
            ->defaultValue('')
            ->validate()
            ->always(function (string $filter) {
                return $this->filterParser->getAst(
                    $filter,
                    $this->engine->getConfiguration()
                        ->getFilterableAttributes()
                );
            })
            ->end()
            ->end()
            ->arrayNode('attributesToRetrieve')
            ->defaultValue(['*'])
            ->scalarPrototype()
            ->end()
            ->end()
            ->arrayNode('sort')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->validate()
            ->always(function (array $sort) {
                return Sorting::fromArray($sort, $this->engine);
            })
            ->end()
            ->end()
            ->integerNode('hitsPerPage')
            ->min(1)
            ->defaultValue(20)
            ->end()
            ->integerNode('page')
            ->min(1)
            ->defaultValue(1)
            ->end()
            ->end();

        return $treeBuilder;
    }

    private function handleFilterAstNode(Node $node, array &$whereStatement): void
    {
        if ($node instanceof Group) {
            $whereStatement[] = '(';
            foreach ($node->getChildren() as $child) {
                $this->handleFilterAstNode($child, $whereStatement);
            }
            $whereStatement[] = ')';
        }

        if ($node instanceof Filter) {
            // Multi filterable need a sub query
            if (in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                $whereStatement[] = $this->engine->getIndexInfo()->getAliasForTable(
                    IndexInfo::TABLE_NAME_DOCUMENTS
                ) . '.id IN (';
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';

            // Single attributes are on the document itself
            } else {
                $whereStatement[] = $this->engine->getIndexInfo()->getAliasForTable(
                    IndexInfo::TABLE_NAME_DOCUMENTS
                ) . '.' . $node->attribute;
                $whereStatement[] = $node->operator->value;
                $whereStatement[] = $this->queryBuilder->createNamedParameter($node->value);
            }
        }

        if ($node instanceof Concatenator) {
            $whereStatement[] = $node->getConcatenator();
        }
    }

    private function limitPagination(): void
    {
        $this->queryBuilder->setFirstResult(
            ($this->searchParameters['page'] - 1) * $this->searchParameters['hitsPerPage']
        );
        $this->queryBuilder->setMaxResults($this->searchParameters['hitsPerPage']);
    }

    private function searchDocuments(): void
    {
        $query = $this->searchParameters['q'];

        if ($query === '') {
            return;
        }

        $terms = $this->engine->getTokenizer()
            ->tokenize($query);

        $ors = [];

        foreach ($terms as $term) {
            $whereStatement = [];
            $whereStatement[] = $this->engine->getIndexInfo()->getAliasForTable(
                IndexInfo::TABLE_NAME_DOCUMENTS
            ) . '.id IN (';
            $whereStatement[] = $this->createSubQueryForTerm($term);
            $whereStatement[] = ')';

            $ors[] = implode(' ', $whereStatement);
        }

        $this->queryBuilder->andWhere('(' . implode(') OR (', $ors) . ')');
    }

    private function selectDocuments(): void
    {
        $this->queryBuilder
            ->addSelect($this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.document')
            ->from(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
            )
        ;
    }

    private function selectTotalHits(): void
    {
        $this->queryBuilder->addSelect('COUNT() OVER() AS totalHits');
    }

    private function sortDocuments(): void
    {
        /** @var Sorting $sorting */
        $sorting = $this->searchParameters['sort'];
        $sorting->applySorters($this->queryBuilder);
    }
}
