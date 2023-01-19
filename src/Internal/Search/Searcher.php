<?php

namespace Terminal42\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Index;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Util;

class Searcher
{
    private QueryBuilder $queryBuilder;

    public function __construct(private Engine $engine, private Parser $filterParser, private array $searchParameters)
    {
        $this->searchParameters = (new Processor())->process(
            $this->getConfigTreeBuilderForSearchParameters()->buildTree(),
            [$this->searchParameters]
        );
    }

    public function fetchResult(): array
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->engine->getConnection()->createQueryBuilder();

        $this->selectDocuments();
        $this->filterDocuments();
        $this->groupDocuments();
        $this->sortDocuments();

        dd($this->queryBuilder->getSQL());
        $showAllAttributes = ['*'] === $this->searchParameters['attributesToReceive'];
        $attributesToReceive = array_flip($this->searchParameters['attributesToReceive']);

        $hits = [];
        foreach ($this->queryBuilder->fetchAllAssociative() as $result) {
            $document = json_decode($result['document'], true);

            $hits[] = $showAllAttributes ? $document : array_intersect_key($document, $attributesToReceive);
        }

        $end = (int) floor(microtime(true) * 1000);

        return [
            "hits" => $hits,
          //  "estimatedTotalHits" => 66,
            "query" => $this->searchParameters['q'],
          //  "limit" => 20,
         //   "offset" => 0,
            "processingTimeMs" => $end - $start,
        ];
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
            ->end()
            ->arrayNode('attributesToReceive')
                ->defaultValue(['*'])
                ->scalarPrototype()->end()
            ->end()
            ->arrayNode('sort')
                ->defaultValue([])
                ->scalarPrototype()->end()
                ->validate()
                    ->always(function(array $sort) {
                        $perAttribute = [];

                        foreach ($sort as $v) {
                            if (!is_string($v)) {
                                throw new \InvalidArgumentException('Sort parameters must be an array of strings.');
                            }

                            $chunks = explode(':', $v, 2);

                            if (2 !== count($chunks) || !in_array($chunks[1], ['asc', 'desc'], true)) {
                                throw new \InvalidArgumentException('Sort parameters must be in the following format: ["title:asc"].');
                            }

                            IndexInfo::validateAttributeName($chunks[0]);

                            if (!in_array($chunks[0], $this->engine->getConfiguration()->getValue('sortableAttributes'), true)) {
                                throw new \InvalidArgumentException(sprintf(
                                    'Cannot sort by "%s". It must be defined as sortable attribute.',
                                    $chunks[0]
                                ));
                            }

                            $perAttribute[$chunks[0]] = strtoupper($chunks[1]);
                        }

                        return $perAttribute;
                    })
            ->end()
        ->end();

        return $treeBuilder;
    }

    private function selectDocuments(): void
    {
        $this->queryBuilder
            ->select('documents.document')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, 'documents')
        ;
    }

    private function filterDocuments(): void
    {
        if ('' === $this->searchParameters['filter']) {
            return;
        }

        $ast = $this->filterParser->getAst($this->searchParameters['filter']);
        dd($ast);

        // TODO: Convert our AST to a valid filter expression (or re-use existing library)
        // and works with more complex queries over multiple attributes such as
        // "(genres = 'Drama' OR genres = 'War') AND (foobar = 'Foo' OR genres = 'War') AND foobar2 = 'foo'"
        $filters = [
            'multi' => [
           //     ['genres', '=', 'Drama'],
            ],
            'single' => [
              //  ['release_date', '>', 0],
            ]
        ];

        foreach ($filters['multi'] as $filter) {
            $attribute = $filter[0];
            $operator = $filter[1];
            $value = $filter[2];
            $subQueryAlias = 'multi_filter_' . $attribute;
            $attributeTableAlias = 'multi_attribute_' . $attribute;
            $attributeDocumentRelationAlias = 'multi_documents_attribute_' . $attribute;

            $subQuery = $this->engine->getConnection()->createQueryBuilder();
            $subQuery
                ->select('document')
                ->from(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, $attributeDocumentRelationAlias)
                ->innerJoin(
                    $attributeDocumentRelationAlias,
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    $attributeTableAlias,
                    sprintf('%s.id = %s.attribute',
                        $attributeTableAlias,
                        $attributeDocumentRelationAlias,
                    )
                )
            ;

            $column = is_float($value) ? 'numeric_value' : 'string_value';

            $subQuery->andWhere(
                sprintf('%s.%s %s %s',
                    $attributeTableAlias,
                    $column,
                    $operator,
                    $this->queryBuilder->createNamedParameter($value)
                )
            );

            $this->queryBuilder
                ->innerJoin(
                    'documents',
                    '(' . $subQuery->getSQL() . ')',
                    $subQueryAlias,
                    sprintf('%s.document = documents.id', $subQueryAlias)
                );
        }

        foreach ($filters['single'] as $filter) {
            $attribute = $filter[0];
            $operator = $filter[1];
            $value = $filter[2];

            $this->queryBuilder
                ->andWhere(sprintf('documents.%s %s %s',
                    $attribute,
                    $operator,
                    $this->queryBuilder->createNamedParameter($value)
                ))
            ;
        }
    }

    private function groupDocuments(): void
    {
        $this->queryBuilder->addGroupBy('documents.id');

        foreach (array_keys($this->searchParameters['sort']) as $sortAttribute) {
            $this->queryBuilder->addGroupBy($sortAttribute);
        }
    }

    private function sortDocuments(): void
    {
        foreach ($this->searchParameters['sort'] as $attributeName => $direction) {
            $this->queryBuilder->addOrderBy($attributeName, $direction);

        }
    }
}