<?php

namespace Terminal42\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Internal\IndexManager;
use Terminal42\Loupe\Internal\Util;

class ResultFetcher
{
    private QueryBuilder $queryBuilder;

    public function __construct(private IndexManager $indexManager, private array $searchParameters, private string $indexName) {

        $this->searchParameters = (new Processor())->process(
            $this->getConfigTreeBuilderForSearchParameters()->buildTree(),
            [$this->searchParameters]
        );
    }

    public function fetchResult(): array
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->indexManager->getConnection()->createQueryBuilder();

        $this->selectDocuments();
        $this->joinFilterableAndSortableAttributes();
        $this->filterDocuments();
        $this->sortDocuments();

        $hits = [];
        foreach ($this->queryBuilder->fetchAllAssociative() as $result) {
            $hits[] = json_decode($result['document'], true);
        }

        $end = (int) floor(microtime(true) * 1000);

        return [
            "hits" => $hits,
            "estimatedTotalHits" => 66,
            "query" => $this->searchParameters['q'],
            "limit" => 20,
            "offset" => 0,
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

                            Util::validateAttributeName($chunks[0]);

                            if (!in_array($chunks[0], $this->indexManager->getConfigurationValueForIndex($this->indexName, 'sortableAttributes'), true)) {
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
            ->from(IndexManager::TABLE_NAME_DOCUMENTS, 'documents')
            ->where('documents.index_name = :index_name')
            ->setParameter('index_name', $this->indexName)
        ;
    }

    private function joinFilterableAndSortableAttributes(): void
    {
        $attributes = $this->indexManager->getFilterableAndSortableAttributes($this->indexName);

        foreach ($attributes as $attribute) {
            $joinAlias = $this->getJoinAliasForAttribute($attribute);
            $attributePlaceholder = $this->queryBuilder->createNamedParameter($attribute);

            $this->queryBuilder
                ->leftJoin(
                    'documents',
                    IndexManager::TABLE_NAME_ATTRIBUTES,
                    $joinAlias,
                    sprintf('documents.id = %s.document AND %s.attribute = %s',
                        $joinAlias,
                        $joinAlias,
                        $attributePlaceholder
                    )
                )
            ;
        }
    }

    private function getJoinAliasForAttribute(string $attributeName): string
    {
        return 'attribute_' . $attributeName;
    }

    private function sortDocuments(): void
    {
        foreach ($this->searchParameters['sort'] as $attributeName => $direction) {
            $this->queryBuilder->addOrderBy(
                $this->getJoinAliasForAttribute($attributeName) . '.numeric_value',
                $direction
            );
            $this->queryBuilder->addOrderBy(
                $this->getJoinAliasForAttribute($attributeName) . '.string_value',
                $direction
            );
        }
    }

    private function filterDocuments(): void
    {
        // TODO: write a lexer that understands this and converts to a valid filter expression (or re-use existing library)
        $filters = [
            ['genres', '=', 'Drama'],
        ];

        foreach ($filters as $filter) {
            $column = is_float($filter[2]) ? 'numeric_value' : 'string_value';

            $this->queryBuilder->andWhere(
                sprintf('%s.%s %s %s',
                    $this->getJoinAliasForAttribute($filter[0]),
                    $column,
                    $filter[1],
                    $this->queryBuilder->createNamedParameter($filter[2])
                )
            );
        }
    }
}