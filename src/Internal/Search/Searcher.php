<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Location\Bounds;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
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
    public const CTE_ALL_MULTI_FILTERS_PREFIX = '_cte_mf_all_';

    public const CTE_MATCHES = '_cte_matches';

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
     * @var array<string, Cte>
     */
    private array $ctesByName = [];

    /**
     * @var array<string, array<string, Cte>>
     */
    private array $ctesByTag = [];

    private FilterBuilder $filterBuilder;

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    public function __construct(
        private Engine $engine,
        Parser $filterParser,
        private SearchParameters $searchParameters
    ) {
        $this->sorting = Sorting::fromArray($this->searchParameters->getSort(), $this->engine);
        $this->queryBuilder = $this->engine->getConnection()->createQueryBuilder();
        $this->filterBuilder = new FilterBuilder($this->engine, $this, $filterParser->getAst($this->searchParameters->getFilter()));
    }

    /**
     * This creates a UNION ALL CTE for all the filter CTEs that were added
     * for a specific attribute. So if you e.g. searched for "multi IN ('foobar') OR multi IN('baz')", it will
     * UNION those two filter CTEs in order to find all matching rows.
     */
    public function addAllMultiFiltersCte(string $attribute): ?string
    {
        $cteName = self::CTE_ALL_MULTI_FILTERS_PREFIX . $attribute;

        if ($this->hasCTE($cteName)) {
            return $cteName;
        }

        $unions = [];

        foreach ($this->getCtesByTag('attribute:' . $attribute) as $cte) {
            $unions[] = sprintf('SELECT document_id, attribute, numeric_value, string_value FROM %s', $cte->getName());
        }

        if ($unions === []) {
            return null;
        }

        $qb = $this->engine->getConnection()->createQueryBuilder();
        $qb->select('document_id', 'attribute', 'numeric_value', 'string_value');
        $qb->from('(' . implode(' UNION ALL ', $unions) . ')');

        $this->addCTE(new Cte($cteName, ['document_id', 'attribute', 'numeric_value', 'string_value'], $qb));

        return $cteName;
    }

    public function addCTE(Cte $cte): void
    {
        $this->ctesByName[$cte->getName()] = $cte;

        foreach ($cte->getTags() as $tag) {
            $this->ctesByTag[$tag][$cte->getName()] = $cte;
        }
    }

    public function addGeoDistanceCte(string $attribute, float $latitude, float $longitude, ?Bounds $bounds = null): string
    {
        $cteName = self::DISTANCE_ALIAS . '_' . $attribute;

        if ($this->hasCTE($cteName)) {
            return $cteName;
        }

        $documentAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $qb = $this->engine->getConnection()->createQueryBuilder()
            ->select($documentAlias . '.id AS document_id')
            ->addSelect(
                sprintf(
                    'loupe_geo_distance(%f, %f, %s, %s) AS distance',
                    $latitude,
                    $longitude,
                    $documentAlias . '.' . $attribute . '_geo_lat',
                    $documentAlias . '.' . $attribute . '_geo_lng',
                )
            )
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, $documentAlias)
            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            // We use floor() and ceil() respectively to ensure we get matches as the BearingSpherical calculation of the
            // BBOX may not be as precise so when searching for the e.g. 3rd decimal floating point, we might exclude
            // locations we shouldn't.
            ->andWhere(implode(' ', $this->filterBuilder->createGeoBoundingBoxWhereStatement($attribute, $bounds)))
            ->groupBy($documentAlias . '.id')
        ;

        $this->addCTE(new Cte($cteName, ['document_id', 'distance'], $qb));

        return $cteName;
    }

    public function fetchResult(): SearchResult
    {
        $start = (int) floor(microtime(true) * 1000);

        $tokens = $this->getTokens();
        $tokensIncludingStopwords = $this->getTokensIncludingStopwords();

        // Now it's time to add our CTEs
        $this->selectDocuments();
        $this->searchDocuments($tokens); // First, add the search term CTEs
        $this->filterDocuments($tokens); // Then filter the documents (requires the search term CTEs)
        $this->selectTotalHits();
        $this->sortDocuments();
        $this->selectDistance();
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

    public function getCTE(string $name): ?Cte
    {
        return $this->ctesByName[$name] ?? null;
    }

    public function getCTENameForToken(string $prefix, Token $token): string
    {
        // For debugging: return $prefix . $token->getId() . '_' .  $token->getTerm();
        return $prefix . $token->getId();
    }

    /**
     * @return array<string, Cte>
     */
    public function getCtesByName(): array
    {
        return $this->ctesByName;
    }

    /**
     * @return array<string, Cte>
     */
    public function getCtesByTag(string $tag): array
    {
        return $this->ctesByTag[$tag] ?? [];
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
        return isset($this->ctesByName[$cteName]);
    }

    private function addTermDocumentMatchesCTE(Token $token, ?Token $previousPhraseToken): void
    {
        // No term matches CTE -> no term document matches CTE
        $termMatchesCTE = $this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token);

        if (!$this->hasCTE($termMatchesCTE)) {
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

        if (['*'] !== $this->searchParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->queryBuilder->createNamedParameter($this->searchParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
            ));
        }

        $cteSelectQb->andWhere(sprintf($termsDocumentsAlias . '.term IN (SELECT id FROM %s)', $this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token)));

        // Ensure phrase positions if any (token itself must be part of the phrase and the previous token must also be of that same phrase)
        if ($token->isPartOfPhrase() && $previousPhraseToken) {
            $cteSelectQb->andWhere(sprintf(
                '%s.position = (SELECT position + 1 FROM %s WHERE document=td.document AND attribute=td.attribute)',
                $termsDocumentsAlias,
                $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $previousPhraseToken),
            ));
        }

        $cteSelectQb->addOrderBy('position');
        $cteSelectQb->setMaxResults(self::MAX_DOCUMENT_MATCHES);

        $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);

        $this->addCTE(new Cte(
            $cteName,
            ['document', 'term', 'attribute', 'position', 'typos'],
            $cteSelectQb
        ));
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
        $this->addCTE(new Cte($this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token), ['id'], $cteSelectQb));
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

    private function buildTokenFrom(TokenCollection $tokenCollection): string
    {
        if ($tokenCollection->empty()) {
            return '';
        }

        $qb = $this->engine->getConnection()->createQueryBuilder()
            ->select(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS) . '.id AS document_id'
            )
            ->from(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
            );

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
            $qb->andWhere('(' . $where . ')');
        }

        $whereNot = implode(' AND ', array_map(
            fn ($statements) => '(' . implode(' AND ', $statements) . ')',
            $negativeConditions
        ));

        if ($whereNot !== '') {
            $qb->andWhere('(' . $whereNot . ')');
        }

        return $qb->getSQL();
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

        if (!$this->hasCTE($cteName)) {
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

    private function filterDocuments(TokenCollection $tokenCollection): void
    {
        $froms = [];
        $qbMatches = $this->engine->getConnection()->createQueryBuilder();
        $qbMatches->select('document_id');

        // User filters
        $froms[] = $this->filterBuilder->buildFrom();

        // User query
        $froms[] = $this->buildTokenFrom($tokenCollection);

        // Drop empty froms
        $froms = array_values(array_filter($froms));

        // Not filtered by either filters or user query, fetch everything
        if ($froms === []) {
            $froms[] = sprintf(
                '(SELECT %s.id AS document_id FROM %s %s)',
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
            );
        }

        if (\count($froms) === 1) {
            $qbMatches->from('(' . $froms[0] . ')');
        } else {
            $qbMatches->from('(' . implode(' INTERSECT ', $froms) . ')');
        }

        $qbMatches->groupBy('document_id');

        $this->addCTE(new Cte(self::CTE_MATCHES, ['document_id'], $qbMatches));
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

        if ($this->ctesByName !== []) {
            $queryParts[] = 'WITH';
            foreach ($this->ctesByName as $name => $cte) {
                $queryParts[] = sprintf(
                    '%s (%s) AS (%s)',
                    $name,
                    implode(',', $cte->getColumnAliasList()),
                    $cte->getQueryBuilder()->getSQL()
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
        $this->addTermDocumentMatchesCTEs($tokenCollection);
    }

    private function selectDistance(): void
    {
        foreach ($this->searchParameters->getAttributesToRetrieve() as $attribute) {
            if (str_starts_with($attribute, '_geoDistance(')) {
                $attribute = (string) preg_replace('/^_geoDistance\((' . Configuration::ATTRIBUTE_NAME_RGXP . ')\)$/', '$1', $attribute);
                $cteName = self::DISTANCE_ALIAS . '_' . $attribute;

                if (!$this->hasCTE($cteName)) {
                    continue;
                }

                $this->queryBuilder->addSelect($cteName . '.distance AS ' . self::DISTANCE_ALIAS . '_' . $attribute);
                $this->queryBuilder
                    ->innerJoin(
                        self::CTE_MATCHES,
                        $cteName,
                        $cteName,
                        sprintf(
                            '%s.document_id = %s.document_id',
                            $cteName,
                            self::CTE_MATCHES
                        )
                    );
            }
        }
    }

    private function selectDocuments(): void
    {
        $documentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $this->queryBuilder
            ->addSelect($documentsAlias . '.document')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, $documentsAlias)
            ->innerJoin(
                $documentsAlias,
                self::CTE_MATCHES,
                self::CTE_MATCHES,
                sprintf(
                    '%s.id = %s.document_id',
                    $documentsAlias,
                    self::CTE_MATCHES
                )
            );
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
