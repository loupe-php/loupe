<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Ast;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\FilterBuilder\FilterBuilder;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;
use Loupe\Loupe\Internal\Util;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;

class Searcher
{
    public const CTE_TERM_DOCUMENT_MATCHES_PREFIX = '_cte_term_document_matches_';

    public const CTE_TERM_MATCHES_PREFIX = '_cte_term_matches_';

    public const DISTANCE_ALIAS = '_distance';

    public const RELEVANCE_ALIAS = '_relevance';

    /**
     * If searching for a query that is super broad like "this is taking so long", way too many
     * documents are going to match so we have to internally limit those matches to prevent
     * "endless" search queries.
     */
    private const MAX_DOCUMENT_MATCHES = 1000;

    /**
     * @var array<string, array{cols: array<string>, sql: string}>
     */
    private array $CTEs = [];

    private Ast $filterAst;

    /**
     * @var array<string, bool>
     */
    private array $geoDistanceSelectsAdded = [];

    private bool $multiAttributeJoinAdded = false;

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    public function __construct(
        private Engine $engine,
        Parser $filterParser,
        private SearchParameters $searchParameters
    ) {
        $this->sorting = Sorting::fromArray($this->searchParameters->getSort(), $this->engine);
        $this->filterAst = $filterParser->getAst($this->searchParameters->getFilter());
    }

    public function addGeoDistanceSelectToQueryBuilder(string $attribute, float $latitude, float $longitude): string
    {
        $alias = self::DISTANCE_ALIAS . '_' . $attribute;

        // Do not add multiple times for performance reasons
        if (isset($this->geoDistanceSelectsAdded[$alias])) {
            return $alias;
        }

        $documentAlias = $this->engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        // Add the distance to the select query, so it's also part of the result
        $this->getQueryBuilder()->addSelect(sprintf(
            'loupe_geo_distance(%f, %f, %s, %s) AS %s',
            $latitude,
            $longitude,
            $documentAlias . '.' . $attribute . '_geo_lat',
            $documentAlias . '.' . $attribute . '_geo_lng',
            self::DISTANCE_ALIAS . '_' . $attribute
        ));

        $this->geoDistanceSelectsAdded[$alias] = true;

        return $alias;
    }

    public function addJoinForMultiAttributes(): void
    {
        if ($this->multiAttributeJoinAdded) {
            return;
        }

        $this->queryBuilder
            ->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                sprintf(
                    '%s.id = %s.document',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
            ->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute = %s.id',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                )
            );

        $this->multiAttributeJoinAdded = true;
    }

    public function fetchResult(): SearchResult
    {
        $start = (int) floor(microtime(true) * 1000);

        $this->queryBuilder = $this->engine->getConnection()
            ->createQueryBuilder();

        $tokens = $this->getTokens();
        $tokensIncludingStopwords = $this->getTokensIncludingStopwords();

        $this->selectTotalHits();
        $this->selectDocuments();
        $this->filterDocuments();
        $this->searchDocuments($tokens);
        $this->sortDocuments();
        $this->limitPagination();

        $showAllAttributes = \in_array('*', $this->searchParameters->getAttributesToRetrieve(), true);
        $attributesToRetrieve = array_flip($this->searchParameters->getAttributesToRetrieve());

        $hits = [];

        foreach ($this->query()->iterateAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            foreach ($result as $k => $v) {
                if (str_starts_with($k, self::DISTANCE_ALIAS)) {
                    $document['_geoDistance(' . str_replace(self::DISTANCE_ALIAS . '_', '', $k) . ')'] = (int) round((float) $v);
                }
            }

            if (\array_key_exists(self::DISTANCE_ALIAS, $result)) {
                $document['_geoDistance'] = (int) round($result[self::DISTANCE_ALIAS]);
            }

            $hit = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);

            if ($this->searchParameters->showRankingScore()) {
                $hit['_rankingScore'] = \array_key_exists(self::RELEVANCE_ALIAS, $result) ?
                    round($result[self::RELEVANCE_ALIAS], 5) : 0.0;
            }

            $this->highlight($hit, $tokensIncludingStopwords);

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

    public function getCTENameForToken(string $prefix, Token $token): string
    {
        // For debugging: return $prefix . $token->getId() . '_' .  $token->getTerm();
        return $prefix . $token->getId();
    }

    /**
     * @return array<string, array{cols: array<string>, sql: string}>
     */
    public function getCTEs(): array
    {
        return $this->CTEs;
    }

    public function getFilterAst(): Ast
    {
        return $this->filterAst;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function getSearchParameters(): SearchParameters
    {
        return $this->searchParameters;
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
            ->tokenize(
                $this->searchParameters->getQuery(),
                $this->engine->getConfiguration()->getMaxQueryTokens(),
                $this->engine->getConfiguration()->getStopWords()
            );
    }

    public function getTokensIncludingStopwords(): TokenCollection
    {
        return $this->tokens = $this->engine->getTokenizer()
            ->tokenize(
                $this->searchParameters->getQuery(),
                $this->engine->getConfiguration()->getMaxQueryTokens(),
                []
            );
    }

    public function hasCTE(string $cteName): bool
    {
        return isset($this->CTEs[$cteName]);
    }

    private function addTermDocumentMatchesCTE(Token $token, ?Token $previousPhraseToken): void
    {
        // No term matches CTE -> no term document matches CTE
        $termMatchesCTE = $this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token);

        if (!isset($this->CTEs[$termMatchesCTE])) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsDocumentsAlias . '.document');
        $cteSelectQb->addSelect($termsDocumentsAlias . '.term');
        $cteSelectQb->addSelect($termsDocumentsAlias . '.attribute');
        $cteSelectQb->addSelect($termsDocumentsAlias . '.position');

        // If neither, exactness nor typo is part of the ranking rules, we can omit calculating the info for better performance
        if (\in_array('exactness', $this->engine->getConfiguration()->getRankingRules(), true) || \in_array('typo', $this->engine->getConfiguration()->getRankingRules(), true)) {
            $cteSelectQb->addSelect(sprintf(
                'loupe_levensthein((SELECT term FROM %s WHERE id=%s.term), %s, %s) AS typos',
                IndexInfo::TABLE_NAME_TERMS,
                $termsDocumentsAlias,
                $this->getQueryBuilder()->createNamedParameter($token->getTerm()),
                $this->engine->getConfiguration()->getTypoTolerance()->firstCharTypoCountsDouble() ? 'true' : 'false'
            ));
        } else {
            $cteSelectQb->addSelect('0 AS typos');
        }

        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);

        // Get documents that match any of our terms
        $documentConditions = [];
        foreach ($this->getTokens()->all() as $t) {
            $cteName = '_cte_term_documents_' . $t->getId();
            if (!$this->hasCTE($cteName)) {
                continue;
            }
            $documentConditions[] = sprintf('%s.document IN (SELECT document FROM %s)', $termsDocumentsAlias, $cteName);
        }

        if ($documentConditions === []) {
            return;
        }

        $cteSelectQb->where('(' . implode(' OR ', $documentConditions) . ')');
        $cteSelectQb->andWhere(sprintf($termsDocumentsAlias . '.term IN (SELECT id FROM %s)', $termMatchesCTE));

        if (['*'] !== $this->searchParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->queryBuilder->createNamedParameter($this->searchParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
            ));
        }

        // Ensure phrase positions if any
        if ($token->isPartOfPhrase() && $previousPhraseToken) {
            $cteSelectQb->andWhere(sprintf(
                '%s.position = (SELECT position + 1 FROM %s WHERE document=td.document AND attribute=td.attribute)',
                $termsDocumentsAlias,
                $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $previousPhraseToken),
            ));
        }

        $cteSelectQb->addOrderBy('position');

        $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);
        $this->CTEs[$cteName]['cols'] = ['document', 'term', 'attribute', 'position', 'typos'];
        $this->CTEs[$cteName]['sql'] = $cteSelectQb->getSQL();
    }

    private function addTermDocumentMatchesCTEs(TokenCollection $tokenCollection): void
    {
        if ($tokenCollection->empty()) {
            return;
        }

        $previousPhraseToken = null;
        foreach ($tokenCollection->all() as $token) {
            $this->addTermDocumentMatchesCTE($token, $previousPhraseToken);
            $previousPhraseToken = $token->isPartOfPhrase() ? $token : null;
        }
    }

    private function addTermDocumentsCTE(Token $token): void
    {
        // No term matches CTE -> no term documents CTE
        $termMatchesCTE = $this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token);

        if (!isset($this->CTEs[$termMatchesCTE])) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect('DISTINCT ' . $termsDocumentsAlias . '.document');

        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);
        $cteSelectQb->where(sprintf('%s.term IN (SELECT id FROM %s)', $termsDocumentsAlias, $termMatchesCTE));

        if (['*'] !== $this->searchParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->queryBuilder->createNamedParameter($this->searchParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
            ));
        }

        $cteSelectQb->setMaxResults(self::MAX_DOCUMENT_MATCHES);

        $cteName = '_cte_term_documents_' . $token->getId();
        $this->CTEs[$cteName]['cols'] = ['document'];
        $this->CTEs[$cteName]['sql'] = $cteSelectQb->getSQL();
    }

    private function addTermDocumentsCTEs(TokenCollection $tokenCollection): void
    {
        if ($tokenCollection->empty()) {
            return;
        }

        foreach ($tokenCollection->all() as $token) {
            $this->addTermDocumentsCTE($token);
        }
    }

    private function addTermMatchesCTE(Token $token, bool $isLastToken): void
    {
        $termsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->addSelect($termsAlias . '.id');
        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS, $termsAlias);

        $ors = [$this->createWherePartForTerm($token->getTerm(), false)];

        foreach ($token->getVariants() as $term) {
            $ors[] = $this->createWherePartForTerm($term, false);
        }

        // Prefix search
        if ($isLastToken &&
            !$token->isPartOfPhrase() &&
            $token->getLength() >= $this->engine->getConfiguration()->getMinTokenLengthForPrefixSearch()
        ) {
            // With typo tolerance on prefix search requires searching the prefix tables as well
            if ($this->engine->getConfiguration()->getTypoTolerance()->isEnabledForPrefixSearch()) {
                $ors[] = $this->createWherePartForTerm($token->getTerm(), true);
            } else {
                // Otherwise, prefix search is just a simple LIKE <token>% for better performance
                $ors[] = sprintf(
                    '%s.term LIKE %s',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                    $this->queryBuilder->createNamedParameter($token->getTerm() . '%')
                );
            }
        }

        $cteSelectQb->where('(' . implode(') OR (', $ors) . ')');

        $this->CTEs[$this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token)]['cols'] = ['id'];
        $this->CTEs[$this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token)]['sql'] = $cteSelectQb->getSQL();
    }

    private function addTermMatchesCTEs(TokenCollection $tokenCollection): void
    {
        if ($tokenCollection->empty()) {
            return;
        }

        foreach ($tokenCollection->all() as $token) {
            $this->addTermMatchesCTE($token, $token === $tokenCollection->last());
        }
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

    /**
     * @param array<int> $states
     */
    private function createStatesMatchWhere(array $states, string $table, string $term, int $levenshteinDistance, string $termColumnName): string
    {
        $where = [];
        /**
         * WHERE
         *     state IN (:states)
         *     AND
         *     LENGTH(term) >= <term> - <lev-distance>
         *     AND
         *     LENGTH(term) <= <term> + <lev-distance>
         *     AND
         *     loupe_max_levenshtein(<term>, $termColumnName, <distance>)
         */
        $where[] = sprintf(
            '%s.state IN (%s)',
            $this->engine->getIndexInfo()
                ->getAliasForTable($table),
            implode(',', $states)
        );
        $where[] = 'AND';
        $where[] = sprintf(
            '%s.length >= %d',
            $this->engine->getIndexInfo()
                ->getAliasForTable($table),
            mb_strlen($term, 'UTF-8') - 1
        );
        $where[] = 'AND';
        $where[] = sprintf(
            '%s.length <= %d',
            $this->engine->getIndexInfo()
                ->getAliasForTable($table),
            mb_strlen($term, 'UTF-8') + 1
        );
        $where[] = 'AND';
        $where[] = sprintf(
            'loupe_max_levenshtein(%s, %s.%s, %d, %s)',
            $this->queryBuilder->createNamedParameter($term),
            $this->engine->getIndexInfo()->getAliasForTable($table),
            $termColumnName,
            $levenshteinDistance,
            $this->engine->getConfiguration()->getTypoTolerance()->firstCharTypoCountsDouble() ? 'true' : 'false'
        );

        return implode(' ', $where);
    }

    private function createTermDocumentMatchesCTECondition(Token $token): ?string
    {
        $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);

        if (!isset($this->CTEs[$cteName])) {
            return null;
        }

        return sprintf(
            '%s.id %s (SELECT DISTINCT document FROM %s)',
            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
            $token->isNegated() ? 'NOT IN' : 'IN',
            $cteName
        );
    }

    private function createWherePartForTerm(string $term, bool $prefix): string
    {
        $where = [];
        $termParameter = $this->queryBuilder->createNamedParameter($term);
        $levenshteinDistance = $this->engine->getConfiguration()
            ->getTypoTolerance()
            ->getLevenshteinDistanceForTerm($term);

        /*
         * Without prefix:
         *
         *     term = '<term>'
         *
         * With prefix:
         *
         *     (term = '<term>' OR term LIKE '<term>%')
         */
        if ($prefix) {
            $where[] = '(';
        }

        $where[] = sprintf(
            '%s.term = %s',
            $this->engine->getIndexInfo()
                ->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
            $termParameter
        );

        if ($prefix) {
            $where[] = 'OR';
            $where[] = sprintf(
                '%s.term LIKE %s',
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $this->queryBuilder->createNamedParameter($term . '%')
            );
            $where[] = ')';
        }

        // Typo tolerance not enabled enabled
        if ($levenshteinDistance === 0) {
            return implode(' ', $where);
        }

        $states = $this->engine->getStateSetIndex()->findMatchingStates($term, $levenshteinDistance, 1);

        // No result possible, we add AND 1=0 to ensure no results
        if ($states === []) {
            $where[] = 'AND 1=0';

            return implode(' ', $where);
        }

        $where[] = 'OR';

        /*
         * Without prefix:
         *
         *     <states-match-terms-table-query>
         *
         * With prefix:
         *
         *     (<states-match-terms-table-query> OR <states-match-prefixes-table-query>)
         */
        if ($prefix) {
            $where[] = '(';
        }

        $where[] = $this->createStatesMatchWhere(
            $states,
            IndexInfo::TABLE_NAME_TERMS,
            $term,
            $levenshteinDistance,
            'term'
        );

        if ($prefix) {
            $where[] = 'OR';
            $where[] = sprintf(
                '%s.id IN (SELECT %s.term FROM %s %s WHERE %s.prefix IN (SELECT %s.id FROM %s %s WHERE %s))',
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_PREFIXES_TERMS),
                IndexInfo::TABLE_NAME_PREFIXES_TERMS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_PREFIXES_TERMS),
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_PREFIXES_TERMS),
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_PREFIXES),
                IndexInfo::TABLE_NAME_PREFIXES,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_PREFIXES),
                $this->createStatesMatchWhere(
                    $states,
                    IndexInfo::TABLE_NAME_PREFIXES,
                    $term,
                    $levenshteinDistance,
                    'prefix'
                )
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

        $filterBuilder = new FilterBuilder($this->engine, $this, $this->queryBuilder);
        $filterBuilder->buildForDocument();
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

        $searchableAttributes = ['*'] === $this->engine->getConfiguration()->getSearchableAttributes() ?
            array_keys($hit) :
            $this->engine->getConfiguration()->getSearchableAttributes();

        $highlightAllAttributes = ['*'] === $this->searchParameters->getAttributesToHighlight();
        $attributesToHighlight = $highlightAllAttributes ?
            $searchableAttributes :
            $this->searchParameters->getAttributesToHighlight()
        ;

        $highlightStartTag = $this->searchParameters->getHighlightStartTag();
        $highlightEndTag = $this->searchParameters->getHighlightEndTag();

        foreach ($searchableAttributes as $attribute) {
            // Do not include any attribute not required by the result (limited by attributesToRetrieve)
            if (!isset($formatted[$attribute])) {
                continue;
            }

            if (\is_array($formatted[$attribute])) {
                foreach ($formatted[$attribute] as $key => $formattedEntry) {
                    $highlightResult = $this->engine->getHighlighter()
                        ->highlight(
                            $formattedEntry,
                            $tokenCollection,
                            $highlightStartTag,
                            $highlightEndTag
                        );

                    if (\in_array($attribute, $attributesToHighlight, true)) {
                        $formatted[$attribute][$key] = $highlightResult->getHighlightedText();
                    }

                    if ($this->searchParameters->showMatchesPosition() && $highlightResult->getMatches() !== []) {
                        $matchesPosition[$attribute][$key] = $highlightResult->getMatches();
                    }
                }
            } else {
                $highlightResult = $this->engine->getHighlighter()
                    ->highlight(
                        (string) $formatted[$attribute],
                        $tokenCollection,
                        $highlightStartTag,
                        $highlightEndTag
                    );

                if (\in_array($attribute, $attributesToHighlight, true)) {
                    $formatted[$attribute] = $highlightResult->getHighlightedText();
                }

                if ($this->searchParameters->showMatchesPosition() && $highlightResult->getMatches() !== []) {
                    $matchesPosition[$attribute] = $highlightResult->getMatches();
                }
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
        $this->addTermMatchesCTEs($tokenCollection);
        $this->addTermDocumentsCTEs($tokenCollection);
        $this->addTermDocumentMatchesCTEs($tokenCollection);

        $positiveConditions = [];
        $negativeConditions = [];

        foreach ($tokenCollection->getGroups() as $tokenOrPhrase) {
            $statements = [];
            foreach ($tokenOrPhrase->getTokens() as $token) {
                $statements[] = $this->createTermDocumentMatchesCTECondition($token);
            }

            if (\count(array_filter($statements))) {
                if ($tokenOrPhrase->isNegated()) {
                    $negativeConditions[] = $statements;
                } else {
                    $positiveConditions[] = $statements;
                }
            }
        }

        $where = implode(' OR ', array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $positiveConditions
        ));

        if ($where !== '') {
            $this->queryBuilder->andWhere('(' . $where . ')');
        }

        $whereNot = implode(' AND ', array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $negativeConditions
        ));

        if ($whereNot !== '') {
            $this->queryBuilder->andWhere('(' . $whereNot . ')');
        }
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
            ->groupBy($this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.document')
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
