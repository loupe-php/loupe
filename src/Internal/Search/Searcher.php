<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Exception\InvalidSearchParametersException;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Ast\Ast;
use Terminal42\Loupe\Internal\Filter\Ast\Concatenator;
use Terminal42\Loupe\Internal\Filter\Ast\Filter;
use Terminal42\Loupe\Internal\Filter\Ast\GeoDistance;
use Terminal42\Loupe\Internal\Filter\Ast\Group;
use Terminal42\Loupe\Internal\Filter\Ast\Node;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Search\Sorting\GeoPoint;
use Terminal42\Loupe\Internal\Search\Sorting\Relevance;
use Terminal42\Loupe\Internal\Tokenizer\TokenCollection;
use Terminal42\Loupe\Internal\Util;
use voku\helper\UTF8;

class Searcher
{
    public const CTE_TERM_DOCUMENT_MATCHES = '_cte_term_document_matches';

    public const CTE_TERM_MATCHES = '_cte_term_matches';

    /**
     * @var array<string, array{'cols': array, 'sql': string}>
     */
    private array $CTEs = [];

    private string $id;

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

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

        $this->sorting = Sorting::fromArray($this->searchParameters['sort'], $this->engine);
        $this->id = uniqid('lqi', true);
    }

    public function fetchResult(): array
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->engine->getConnection()
            ->createQueryBuilder();

        $tokens = $this->getTokens();

        $this->selectTotalHits();
        $this->selectDocuments();
        $this->filterDocuments();
        $this->searchDocuments($tokens);
        $this->sortDocuments();
        $this->limitPagination();

        $showAllAttributes = ['*'] === $this->searchParameters['attributesToRetrieve'];
        $attributesToRetrieve = array_flip($this->searchParameters['attributesToRetrieve']);

        $hits = [];

        foreach ($this->query()->iterateAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            if (array_key_exists(GeoPoint::DISTANCE_ALIAS, $result)) {
                $document['_geoDistance'] = (int) round($result[GeoPoint::DISTANCE_ALIAS]);
            }

            $hit = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);

            $this->highlight($hit, $tokens);

            $hits[] = $hit;
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

    public function getCTEs(): array
    {
        return $this->CTEs;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function getQueryId(): string
    {
        return $this->id;
    }

    public function getTokens(): TokenCollection
    {
        if ($this->tokens instanceof TokenCollection) {
            return $this->tokens;
        }

        $query = $this->searchParameters['q'];

        if ($query === '') {
            return $this->tokens = new TokenCollection();
        }

        return $this->tokens = $this->engine->getTokenizer()
            ->tokenize($query)
            ->limit(10) // TODO: Test and document this
        ;
    }

    private function addTermDocumentMatchesCTE(): void
    {
        // No term matches CTE -> no term document matches CTE
        if (! isset($this->CTEs[self::CTE_TERM_MATCHES])) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsDocumentsAlias . '.document');
        $cteSelectQb->addSelect($termsDocumentsAlias . '.ntf * ' . sprintf('(SELECT idf FROM %s WHERE td.term=id)', self::CTE_TERM_MATCHES));
        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);
        $cteSelectQb->andWhere(sprintf($termsDocumentsAlias . '.term IN (SELECT id FROM %s)', self::CTE_TERM_MATCHES));
        $cteSelectQb->addOrderBy($termsDocumentsAlias . '.document');
        $cteSelectQb->addOrderBy($termsDocumentsAlias . '.term');

        $this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES]['cols'] = ['document', 'tfidf'];
        $this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES]['sql'] = $cteSelectQb->getSQL();
    }

    private function addTermMatchesCTE(TokenCollection $tokenCollection): void
    {
        if ($tokenCollection->empty()) {
            return;
        }

        $termsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsAlias . '.id');
        $cteSelectQb->addSelect($termsAlias . '.idf');
        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS, $termsAlias);

        $ors = [];

        foreach ($tokenCollection->allTokensWithVariants() as $term) {
            $ors[] = $this->createWherePartForTerm($term);
        }

        $cteSelectQb->where('(' . implode(') OR (', $ors) . ')');
        $cteSelectQb->orderBy($termsAlias . '.id');

        $this->CTEs[self::CTE_TERM_MATCHES]['cols'] = ['id', 'idf'];
        $this->CTEs[self::CTE_TERM_MATCHES]['sql'] = $cteSelectQb->getSQL();
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

    private function createWherePartForTerm(string $term): string
    {
        $termParameter = $this->queryBuilder->createNamedParameter($term);
        $levenshteinDistance = $this->engine->getConfiguration()
            ->getLevenshteinDistanceForTerm($term);

        $where = [];

        if ($levenshteinDistance === 0) {
            /*
             * WHERE
                  term = '<term>'
             */
            $where[] = sprintf(
                '%s.term = %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $termParameter
            );
        } else {
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
             *          max_levenshtein(<term>, term, <distance>)
             *       )
             *    )
             */
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
        }

        return implode(' ', $where);
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
            ->arrayNode('attributesToHighlight')
            ->defaultValue([])
            ->scalarPrototype()
            ->validate()
            ->always(function (string $attribute) {
                if (! \in_array($attribute, $this->engine->getConfiguration()->getSearchableAttributes(), true)) {
                    throw InvalidSearchParametersException::cannotHighlightBecauseNotSearchable($attribute);
                }

                return $attribute;
            })
            ->end()
            ->end()
            ->end()
            ->booleanNode('showMatchesPosition')
            ->defaultFalse()
            ->end()
            ->arrayNode('sort')
            ->defaultValue([Relevance::RELEVANCE_ALIAS . ':desc'])
            ->scalarPrototype()
            ->end()
            ->validate()
            ->always(function (array $sort) {
                // Throws if not valid value
                Sorting::fromArray($sort, $this->engine);

                return $sort;
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
        $documentAlias = $this->engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

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
                $whereStatement[] = $documentAlias . '.id IN (';
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';

            // Single attributes are on the document itself
            } else {
                $whereStatement[] = $documentAlias . '.' . $node->attribute;
                $whereStatement[] = $node->operator->value;
                $whereStatement[] = $this->queryBuilder->createNamedParameter($node->value);
            }
        }

        if ($node instanceof GeoDistance) {
            // Start a group
            $whereStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            $bounds = $node->getBbox();

            // Latitude
            $whereStatement[] = $documentAlias . '._geo_lat';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getSouth();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lat';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getNorth();

            // Longitude
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lng';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getWest();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '._geo_lng';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getEast();

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $whereStatement[] = 'AND';
            $whereStatement[] = sprintf(
                'geo_distance(%f, %f, %s, %s)',
                $node->lat,
                $node->lng,
                $documentAlias . '._geo_lat',
                $documentAlias . '._geo_lng',
            );
            $whereStatement[] = '<=';
            $whereStatement[] = $node->distance;

            // End group
            $whereStatement[] = ')';
        }

        if ($node instanceof Concatenator) {
            $whereStatement[] = $node->getConcatenator();
        }
    }

    private function highlight(array &$hit, TokenCollection $tokenCollection)
    {
        if ($this->searchParameters['attributesToHighlight'] === [] && ! $this->searchParameters['showMatchesPosition']) {
            return;
        }

        $formatted = $hit;
        $matchesPosition = [];

        $highlightAllAttributes = ['*'] === $this->searchParameters['attributesToHighlight'];
        $attributesToHighlight = $highlightAllAttributes ?
            $this->engine->getConfiguration()
                ->getSearchableAttributes() :
            $this->searchParameters['attributesToHighlight']
        ;

        foreach ($this->engine->getConfiguration()->getSearchableAttributes() as $attribute) {
            // Do not include any attribute not required by the result (limited by attributesToRetrieve)
            if (! isset($formatted[$attribute])) {
                continue;
            }

            $highlightResult = $this->engine->getHighlighter()
                ->highlight($formatted[$attribute], $tokenCollection);

            if (in_array($attribute, $attributesToHighlight, true)) {
                $formatted[$attribute] = $highlightResult->getHighlightedText();
            }

            if ($this->searchParameters['showMatchesPosition'] && $highlightResult->getMatches() !== []) {
                $matchesPosition[$attribute] = $highlightResult->getMatches();
            }
        }

        if ($attributesToHighlight !== []) {
            $hit['_formatted'] = $formatted;
        }

        if ($matchesPosition !== []) {
            $hit['_matchesPosition'] = $matchesPosition;
        }
    }

    private function limitPagination(): void
    {
        $this->queryBuilder->setFirstResult(
            ($this->searchParameters['page'] - 1) * $this->searchParameters['hitsPerPage']
        );
        $this->queryBuilder->setMaxResults($this->searchParameters['hitsPerPage']);
    }

    private function query(): Result
    {
        $queryParts = [];

        if ($this->CTEs !== []) {
            $queryParts[] = 'WITH';
            foreach ($this->CTEs as $name => $config) {
                $queryParts[] = sprintf(
                    '%s (%s) AS (%s)',
                    $name,
                    implode(',', $config['cols']),
                    $config['sql']
                );
                $queryParts[] = ',';
            }

            array_pop($queryParts);
        }

        $queryParts[] = $this->queryBuilder->getSQL();
        //dd(implode(' ', $queryParts), $this->queryBuilder->getParameters());
        return $this->engine->getConnection()->executeQuery(
            implode(' ', $queryParts),
            $this->queryBuilder->getParameters(),
            $this->queryBuilder->getParameterTypes(),
        );
    }

    private function searchDocuments(TokenCollection $tokenCollection): void
    {
        $this->addTermMatchesCTE($tokenCollection);
        $this->addTermDocumentMatchesCTE();

        if (! isset($this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES])) {
            return;
        }

        $this->queryBuilder->andWhere(sprintf(
            '%s.id IN (SELECT DISTINCT document FROM %s)',
            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
            self::CTE_TERM_DOCUMENT_MATCHES
        ));
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
        $this->sorting->applySorters($this);
    }
}
