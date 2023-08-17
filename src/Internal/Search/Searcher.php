<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
use Loupe\Loupe\Internal\Filter\Ast\GeoDistance;
use Loupe\Loupe\Internal\Filter\Ast\Group;
use Loupe\Loupe\Internal\Filter\Ast\Node;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Sorting\GeoPoint;
use Loupe\Loupe\Internal\Search\Sorting\Relevance;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;
use Loupe\Loupe\Internal\Util;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use voku\helper\UTF8;

class Searcher
{
    public const CTE_TERM_DOCUMENT_MATCHES = '_cte_term_document_matches';

    public const CTE_TERM_MATCHES = '_cte_term_matches';

    /**
     * @var array<string, array{cols: array<string>, sql: string}>
     */
    private array $CTEs = [];

    private string $id;

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    public function __construct(
        private Engine $engine,
        private Parser $filterParser,
        private SearchParameters $searchParameters
    ) {
        $this->sorting = Sorting::fromArray($this->searchParameters->getSort(), $this->engine);
        $this->id = uniqid('lqi', true);
    }

    public function fetchResult(): SearchResult
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

        $showAllAttributes = ['*'] === $this->searchParameters->getAttributesToRetrieve();
        $attributesToRetrieve = array_flip($this->searchParameters->getAttributesToRetrieve());

        $hits = [];

        foreach ($this->query()->iterateAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            foreach ($result as $k => $v) {
                if (str_starts_with($k, GeoPoint::DISTANCE_ALIAS)) {
                    $document['_geoDistance(' . str_replace(GeoPoint::DISTANCE_ALIAS . '_', '', $k) . ')'] = (int) round((float) $v);
                }
            }

            if (\array_key_exists(GeoPoint::DISTANCE_ALIAS, $result)) {
                $document['_geoDistance'] = (int) round($result[GeoPoint::DISTANCE_ALIAS]);
            }

            $hit = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);

            if ($this->searchParameters->showRankingScore() && \array_key_exists(Relevance::RELEVANCE_ALIAS, $result)) {
                $hit['_rankingScore'] = round($result[Relevance::RELEVANCE_ALIAS], 5);
            }

            $this->highlight($hit, $tokens);

            $hits[] = $hit;
        }

        $totalHits = $result['totalHits'] ?? 0;
        $totalPages = (int) ceil($totalHits / $this->searchParameters->getHitsPerPage());
        $end = (int) floor(microtime(true) * 1000);

        return new SearchResult(
            $hits,
            $this->createAnalyzedQuery($tokens),
            $end - $start,
            $this->searchParameters->getHitsPerPage(),
            $this->searchParameters->getPage(),
            $totalPages,
            $totalHits
        );
    }

    /**
     * @return array<string, array{cols: array<string>, sql: string}>
     */
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

        if ($this->searchParameters->getQuery() === '') {
            return $this->tokens = new TokenCollection();
        }

        return $this->tokens = $this->engine->getTokenizer()
            ->tokenize($this->searchParameters->getQuery(), $this->engine->getConfiguration()->getMaxQueryTokens())
        ;
    }

    private function addTermDocumentMatchesCTE(TokenCollection $tokenCollection): void
    {
        // No term matches CTE -> no term document matches CTE
        if (!isset($this->CTEs[self::CTE_TERM_MATCHES])) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsDocumentsAlias . '.document');

        // This is normalized term frequency (<number of occurrences of term in document>/<total terms in document>)
        // multiplied with the inversed term document frequency.
        // Notice the * 1.0 addition to the COUNT() SELECTS in order to force floating point calculations
        $cteSelectQb->addSelect(
            sprintf(
                '
            1.0 *
            (SELECT COUNT(*) FROM %s WHERE term=td.term AND document=td.document) /
            (SELECT COUNT(*) FROM %s WHERE document=td.document) *
            (SELECT idf FROM %s WHERE td.term=id)',
                IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
                IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
                self::CTE_TERM_MATCHES
            )
        );

        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);

        if (['*'] !== $this->searchParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->queryBuilder->createNamedParameter($this->searchParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
            ));
        }

        $cteSelectQb->andWhere(sprintf($termsDocumentsAlias . '.term IN (SELECT id FROM %s)', self::CTE_TERM_MATCHES));

        // Ensure phrase positions if any
        $previousPhraseTerm = null;
        foreach ($tokenCollection->all() as $token) {
            if ($token->isPartOfPhrase()) {
                if ($previousPhraseTerm === null) {
                    $previousPhraseTerm = $token->getTerm();
                } else {
                    $cteSelectQb->andWhere(sprintf(
                        '%s.position = (SELECT position + 1 FROM %s WHERE term=(SELECT id FROM terms WHERE term=%s) AND document=td.document AND attribute=td.attribute)',
                        $termsDocumentsAlias,
                        IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
                        $this->queryBuilder->createNamedParameter($previousPhraseTerm),
                    ));
                }
            } else {
                $previousPhraseTerm = null;
            }
        }

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

        foreach ($tokenCollection->allTermsWithVariants() as $term) {
            $ors[] = $this->createWherePartForTerm($term);
        }

        // Prefix search
        $lastToken = $tokenCollection->last();

        if ($lastToken !== null &&
            !$lastToken->isPartOfPhrase() &&
            $lastToken->getLength() >= $this->engine->getConfiguration()->getMinTokenLengthForPrefixSearch()
        ) {
            $ors[] = sprintf(
                '%s.term LIKE %s',
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $this->queryBuilder->createNamedParameter($lastToken->getTerm() . '%')
            );
        }

        $cteSelectQb->where('(' . implode(') OR (', $ors) . ')');
        $cteSelectQb->orderBy($termsAlias . '.id');

        $this->CTEs[self::CTE_TERM_MATCHES]['cols'] = ['id', 'idf'];
        $this->CTEs[self::CTE_TERM_MATCHES]['sql'] = $cteSelectQb->getSQL();
    }

    private function createAnalyzedQuery(TokenCollection $tokens): string
    {
        $lastToken = $tokens->last();

        if ($lastToken === null) {
            return $this->searchParameters->getQuery();
        }

        $query = mb_substr($this->searchParameters->getQuery(), 0, $lastToken->getStartPosition() + $lastToken->getLength());

        if ($lastToken->isPartOfPhrase()) {
            $query .= '"';
        }

        return $query;
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

        $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
            (\is_float($node->value) ? 'numeric_value' : 'string_value');

        $sql = $node->operator->isNegative() ?
            $node->operator->opposite()->buildSql($this->engine->getConnection(), $column, $node->value) :
            $node->operator->buildSql($this->engine->getConnection(), $column, $node->value);

        $qb->andWhere($sql);

        return $qb->getSQL();
    }

    private function createWherePartForTerm(string $term): string
    {
        $termParameter = $this->queryBuilder->createNamedParameter($term);
        $levenshteinDistance = $this->engine->getConfiguration()
            ->getTypoTolerance()
            ->getLevenshteinDistanceForTerm($term);

        $where = [];

        if ($levenshteinDistance === 0) {
            /*
             * WHERE
             *     term = '<term>'
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
             *     term = '<term>'
             *     OR
             *     (
             *         state IN (:states)
             *         AND
             *         LENGTH(term) >= <term> - <lev-distance>
             *         AND
             *         LENGTH(term) <= <term> + <lev-distance>
             *         AND
             *         max_levenshtein(<term>, term, <distance>)
             *       )
             */
            $where[] = sprintf(
                '%s.term = %s',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $termParameter
            );
            $where[] = 'OR';
            $where[] = '(';
            $where[] = sprintf(
                '%s.state IN (%s)',
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                implode(',', $this->engine->getStateSetIndex()->findMatchingStates($term, $levenshteinDistance))
            );
            $where[] = 'AND';
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
                'max_levenshtein(%s, %s.term, %d, %s)',
                $termParameter,
                $this->engine->getIndexInfo()
                    ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $levenshteinDistance,
                $this->engine->getConfiguration()->getTypoTolerance()->firstCharTypoCountsDouble() ? 'true' : 'false'
            );
            $where[] = ')';
        }

        return implode(' ', $where);
    }

    private function filterDocuments(): void
    {
        if ($this->searchParameters->getFilter() === '') {
            return;
        }

        $ast = $this->filterParser->getAst($this->searchParameters->getFilter(), $this->engine);
        $whereStatement = [];

        foreach ($ast->getNodes() as $node) {
            $this->handleFilterAstNode($node, $whereStatement);
        }

        $this->queryBuilder->andWhere(implode(' ', $whereStatement));
    }

    /**
     * @param array<string> $whereStatement
     */
    private function handleFilterAstNode(Node $node, array &$whereStatement): void
    {
        $documentAlias = $this->engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

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
            $operator = $node->operator;

            // Not existing attributes need be handled as no match if positive and as match if negative
            if (!\in_array($node->attribute, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $whereStatement[] = $operator->isNegative() ? '1 = 1' : '1 = 0';
            }

            // Multi filterable attributes need a sub query
            elseif (\in_array($node->attribute, $this->engine->getIndexInfo()->getMultiFilterableAttributes(), true)) {
                $whereStatement[] = sprintf($documentAlias . '.id %s (', $operator->isNegative() ? 'NOT IN' : 'IN');
                $whereStatement[] = $this->createSubQueryForMultiAttribute($node);
                $whereStatement[] = ')';

            // Single attributes are on the document itself
            } else {
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

            // Start a group
            $whereStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            $bounds = $node->getBbox();

            // Latitude
            $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lat';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getSouth();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lat';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getNorth();

            // Longitude
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lng';
            $whereStatement[] = '>=';
            $whereStatement[] = $bounds->getWest();
            $whereStatement[] = 'AND';
            $whereStatement[] = $documentAlias . '.' . $node->attributeName . '_geo_lng';
            $whereStatement[] = '<=';
            $whereStatement[] = $bounds->getEast();

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $whereStatement[] = 'AND';
            $whereStatement[] = sprintf(
                'geo_distance(%f, %f, %s, %s)',
                $node->lat,
                $node->lng,
                $documentAlias . '.' . $node->attributeName . '_geo_lat',
                $documentAlias . '.' . $node->attributeName . '_geo_lng'
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

    /**
     * @param array<mixed> $hit
     */
    private function highlight(array &$hit, TokenCollection $tokenCollection): void
    {
        if ($this->searchParameters->getAttributesToHighlight() === [] && !$this->searchParameters->showMatchesPosition()) {
            return;
        }

        $formatted = $hit;
        $matchesPosition = [];

        $highlightAllAttributes = ['*'] === $this->searchParameters->getAttributesToHighlight();
        $attributesToHighlight = $highlightAllAttributes ?
            $this->engine->getConfiguration()->getSearchableAttributes() :
            $this->searchParameters->getAttributesToHighlight()
        ;

        foreach ($this->engine->getConfiguration()->getSearchableAttributes() as $attribute) {
            // Do not include any attribute not required by the result (limited by attributesToRetrieve)
            if (!isset($formatted[$attribute])) {
                continue;
            }

            $highlightResult = $this->engine->getHighlighter()
                ->highlight($formatted[$attribute], $tokenCollection);

            if (\in_array($attribute, $attributesToHighlight, true)) {
                $formatted[$attribute] = $highlightResult->getHighlightedText();
            }

            if ($this->searchParameters->showMatchesPosition() && $highlightResult->getMatches() !== []) {
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
            ($this->searchParameters->getPage() - 1) * $this->searchParameters->getHitsPerPage()
        );
        $this->queryBuilder->setMaxResults($this->searchParameters->getHitsPerPage());
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

        return $this->engine->getConnection()->executeQuery(
            implode(' ', $queryParts),
            $this->queryBuilder->getParameters(),
            $this->queryBuilder->getParameterTypes(),
        );
    }

    private function searchDocuments(TokenCollection $tokenCollection): void
    {
        $this->addTermMatchesCTE($tokenCollection);
        $this->addTermDocumentMatchesCTE($tokenCollection);

        if (!isset($this->CTEs[self::CTE_TERM_DOCUMENT_MATCHES])) {
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
