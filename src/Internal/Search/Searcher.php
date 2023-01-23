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
        $this->sortDocuments();
        $this->limitPagination();

        $showAllAttributes = ['*'] === $this->searchParameters['attributesToReceive'];
        $attributesToReceive = array_flip($this->searchParameters['attributesToReceive']);

        $hits = [];
        foreach ($this->queryBuilder->fetchAllAssociative() as $result) {
            $document = json_decode($result['document'], true);

            $hits[] = $showAllAttributes ? $document : array_intersect_key($document, $attributesToReceive);
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
            ->from(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, 'rel')
            ->innerJoin(
                'rel',
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                'attributes',
                sprintf(
                    'attributes.attribute=%s AND attributes.id = rel.attribute',
                    $this->queryBuilder->createNamedParameter($node->attribute)
                )
            )
        ;

        $column = is_float($node->value) ? 'numeric_value' : 'string_value';

        $qb->andWhere(
            sprintf(
                'attributes.%s %s %s',
                $column,
                $node->operator->value,
                $this->queryBuilder->createNamedParameter($node->value)
            )
        );

        return $qb->getSQL();
    }

    private function filterDocuments(): void
    {
        /** @var Ast $ast */
        $ast = $this->searchParameters['filter'];
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
            ->arrayNode('attributesToReceive')
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
                $perAttribute = [];

                foreach ($sort as $v) {
                    if (! is_string($v)) {
                        throw new \InvalidArgumentException('Sort parameters must be an array of strings.');
                    }

                    $chunks = explode(':', $v, 2);

                    if (count($chunks) !== 2 || ! in_array($chunks[1], ['asc', 'desc'], true)) {
                        throw new \InvalidArgumentException(
                            'Sort parameters must be in the following format: ["title:asc"].'
                        );
                    }

                    IndexInfo::validateAttributeName($chunks[0]);

                    if (! in_array(
                        $chunks[0],
                        $this->engine->getConfiguration()
                            ->getValue('sortableAttributes'),
                        true
                    )) {
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
                $whereStatement[] = 'documents.id IN (';
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';

            // Single attributes are on the document itself
            } else {
                $whereStatement[] = 'documents.' . $node->attribute;
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

    private function selectDocuments(): void
    {
        $this->queryBuilder
            ->addSelect('documents.document')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, 'documents')
        ;
    }

    private function selectTotalHits(): void
    {
        $this->queryBuilder->addSelect('COUNT() OVER() AS totalHits');
    }

    private function sortDocuments(): void
    {
        foreach ($this->searchParameters['sort'] as $attributeName => $direction) {
            $this->queryBuilder->addOrderBy('documents.' . $attributeName, $direction);
        }
    }
}
