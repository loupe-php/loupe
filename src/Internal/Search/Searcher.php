<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Location\Bounds;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Ast\Concatenator;
use Loupe\Loupe\Internal\Filter\Ast\Filter;
use Loupe\Loupe\Internal\Filter\Ast\GeoBoundingBox;
use Loupe\Loupe\Internal\Filter\Ast\GeoDistance;
use Loupe\Loupe\Internal\Filter\Ast\Group;
use Loupe\Loupe\Internal\Filter\Ast\Node;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Tokenizer\Phrase;
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
     * @var array<string, array{cols: array<string>, sql: string}>
     */
    private array $CTEs = [];

    /**
     * @var array<string, bool>
     */
    private array $geoDistanceSelectsAdded = [];

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    public function __construct(
        private Engine $engine,
        private Parser $filterParser,
        private SearchParameters $searchParameters
    ) {
        $this->sorting = Sorting::fromArray($this->searchParameters->getSort(), $this->engine);
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
            ->tokenize($this->searchParameters->getQuery(), $this->engine->getConfiguration()->getMaxQueryTokens())
        ;
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

        $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);
        $this->CTEs[$cteName]['cols'] = ['document', 'term', 'attribute', 'position'];
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
     * @return array<string|float>
     */
    private function createGeoBoundingBoxWhereStatement(string $documentAlias, GeoBoundingBox|GeoDistance $node, Bounds $bounds): array
    {
        $whereStatement = [];

        // Prevent nullable
        $nullTerm = $this->queryBuilder->createNamedParameter(LoupeTypes::VALUE_NULL);
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

        $isFloatType = LoupeTypes::isFloatType(LoupeTypes::getTypeFromValue($node->value));

        $column = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES) . '.' .
            ($isFloatType ? 'numeric_value' : 'string_value');

        $sql = $node->operator->isNegative() ?
            $node->operator->opposite()->buildSql($this->engine->getConnection(), $column, $node->value) :
            $node->operator->buildSql($this->engine->getConnection(), $column, $node->value);

        $qb->andWhere($sql);

        return $qb->getSQL();
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

        $states = $this->engine->getStateSetIndex()->findMatchingStates($term, $levenshteinDistance);

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

            // Add the distance to the select query, so it's also part of the result
            $distanceSelectAlias = $this->addGeoDistanceSelectToQueryBuilder($node->attributeName, $node->lat, $node->lng);

            // Start a group
            $whereStatement[] = '(';

            // Improve performance by drawing a BBOX around our coordinates to reduce the result set considerably before
            // the actual distance is compared. This can use indexes.
            // We use floor() and ceil() respectively to ensure we get matches as the BearingSpherical calculation of the
            // BBOX may not be as precise so when searching for the e.g. 3rd decimal floating point, we might exclude
            // locations we shouldn't.
            $bounds = $node->getBbox();

            $whereStatement = [...$whereStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

            // And now calculate the real distance to filter out the ones that are within the BBOX (which is a square)
            // but not within the radius (which is a circle).
            $whereStatement[] = 'AND';
            $whereStatement[] = $distanceSelectAlias;
            $whereStatement[] = '<=';
            $whereStatement[] = $node->distance;

            // End group
            $whereStatement[] = ')';
        }

        if ($node instanceof GeoBoundingBox) {
            // Not existing attributes need be handled as no match
            if (!\in_array($node->attributeName, $this->engine->getIndexInfo()->getFilterableAttributes(), true)) {
                $whereStatement[] = '1 = 0';
                return;
            }

            // Start a group GeoDistance BBOX
            $whereStatement[] = '(';

            // Same like above for
            $bounds = $node->getBbox();

            $whereStatement = [...$whereStatement, ...$this->createGeoBoundingBoxWhereStatement($documentAlias, $node, $bounds)];

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
        $this->addTermDocumentMatchesCTEs($tokenCollection);
        $positiveConditions = [];
        $negativeConditions = [];

        foreach ($tokenCollection->getGroups() as $tokenOrPhrase) {
            $statements = [];
            foreach ($tokenOrPhrase->getTokens() as $token) {
                $statements[] = $this->createTermDocumentMatchesCTECondition($token);
            }

            if (count(array_filter($statements))) {
                if ($tokenOrPhrase->isNegated()) {
                    $negativeConditions[] = $statements;
                } else {
                    $positiveConditions[] = $statements;
                }
            }
        }

        $wheres = [];
        foreach ($positiveConditions as $statements) {
            $wheres[] = '(' . implode(' AND ', $statements) . ')';
        }

        $where = implode(' OR ', $wheres);
        if ($where !== '') {
            $this->queryBuilder->andWhere('(' . $where . ')');
        }

        $whereNots = [];
        foreach ($negativeConditions as $statements) {
            $whereNots[] = '(' . implode(' AND ', $statements) . ')';
        }

        $whereNot = implode(' AND ', $whereNots);
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
