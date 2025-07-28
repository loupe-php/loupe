<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Location\Bounds;
use Loupe\Loupe\BrowseResult;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidSearchParametersException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Search\FilterBuilder\FilterBuilder;
use Loupe\Loupe\Internal\Util;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Loupe\Matcher\FormatterOptions;
use Loupe\Matcher\FormatterResult;
use Loupe\Matcher\Tokenizer\Token;
use Loupe\Matcher\Tokenizer\TokenCollection;

/**
 * @template T of AbstractQueryParameters
 */
class Searcher
{
    public const CTE_ALL_MULTI_FILTERS_PREFIX = '_cte_mf_all_';

    public const CTE_MATCHES = '_cte_matches';

    public const CTE_TERM_DOCUMENT_MATCHES_PREFIX = '_cte_term_document_matches_';

    public const CTE_TERM_DOCUMENTS_PREFIX = '_cte_term_documents_';

    public const CTE_TERM_MATCHES_PREFIX = '_cte_term_matches_';

    public const DISTANCE_ALIAS = '_distance';

    public const FACET_ALIAS_COUNT_PREFIX = '_facet_count_';

    public const FACET_ALIAS_MIN_MAX_PREFIX = '_facet_minmax_';

    public const MATCH_POSITION_INFO_PREFIX = '_match_position_info_';

    public const RELEVANCE_ALIAS = '_relevance';

    /**
     * @var array<string, Cte>
     */
    private array $ctesByName = [];

    /**
     * @var array<string, array<string, Cte>>
     */
    private array $ctesByTag = [];

    private FilterBuilder $filterBuilder;

    /**
     * @var array<int|float|string, string>
     */
    private array $namedParameters = [];

    private QueryBuilder $queryBuilder;

    private Sorting $sorting;

    private ?TokenCollection $tokens = null;

    /**
     * @param T $queryParameters
     */
    public function __construct(
        private Engine $engine,
        Parser $filterParser,
        private AbstractQueryParameters $queryParameters
    ) {
        if ($this->queryParameters instanceof SearchParameters) {
            $this->sorting = Sorting::fromArray($this->queryParameters->getSort(), $this->engine);
        } else {
            $this->sorting = Sorting::fromArray([], $this->engine);
        }

        $this->queryBuilder = $this->engine->getConnection()->createQueryBuilder();
        $this->filterBuilder = new FilterBuilder($this->engine, $this, $filterParser->getAst($this->queryParameters->getFilter()));
    }

    /**
     * This creates a UNION ALL CTE for all the filter CTEs that were added
     * for a specific attribute. So if you e.g. searched for "multi IN ('foobar') OR multi IN('baz')", it will
     * UNION those two filter CTEs in order to find all matching rows.
     */
    public function addAllMultiFiltersCte(string $attribute, string $alias): ?string
    {
        $cteName = self::CTE_ALL_MULTI_FILTERS_PREFIX . $attribute;

        if ($this->hasCTE($cteName)) {
            return $cteName;
        }

        $unions = [];

        foreach ($this->getCtesByTag('attribute:' . $attribute) as $cte) {
            $unions[] = sprintf('SELECT document_id, %s FROM %s', $alias, $cte->getName());
        }

        if ($unions === []) {
            return null;
        }

        $qb = $this->engine->getConnection()->createQueryBuilder();
        $qb->select('document_id', $alias);
        $qb->from('(' . implode(' UNION ', $unions) . ')');

        $this->addCTE(new Cte($cteName, ['document_id', $alias], $qb));

        return $cteName;
    }

    public function addCTE(Cte $cte): void
    {
        $this->ctesByName[$cte->getName()] = $cte;

        foreach ($cte->getTags() as $tag) {
            $this->ctesByTag[$tag][$cte->getName()] = $cte;
        }
    }

    public function addFromMultiAttributesAndJoinMatches(QueryBuilder $qb, string $attribute): void
    {
        $qb->from(
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
        )
            ->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                sprintf(
                    '%s.attribute=%s AND %s.id = %s.attribute',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->createNamedParameter($attribute),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                )
            )
            ->innerJoin(
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
                self::CTE_MATCHES,
                self::CTE_MATCHES,
                sprintf(
                    '%s.document_id = %s.document',
                    self::CTE_MATCHES,
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)
                )
            );
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

    public function createNamedParameter(mixed $value, mixed $type = ParameterType::STRING): string
    {
        if ($type === ParameterType::STRING && \is_scalar($value)) {
            if (isset($this->namedParameters[$value])) {
                return $this->namedParameters[$value];
            }

            return $this->namedParameters[$value] = $this->queryBuilder->createNamedParameter($value, $type);
        }

        return $this->queryBuilder->createNamedParameter($value, $type);
    }

    /**
     * @return (T is SearchParameters ? SearchResult : BrowseResult)
     */
    public function fetchResult(): AbstractQueryResult
    {
        $start = (int) floor(microtime(true) * 1000);

        $tokens = $this->getTokens();
        $tokensIncludingStopwords = $this->engine->getTokenizer()->tokenize(
            $this->queryParameters->getQuery(),
            $this->engine->getConfiguration()->getMaxQueryTokens(),
        );

        // Now it's time to add our CTEs
        $this->selectDocuments();
        $this->searchDocuments($tokens); // First, add the search term CTEs
        $this->addPositionsForFormatting($tokens);
        $this->filterDocuments($tokens); // Then filter the documents (requires the search term CTEs)
        $this->addFacets();
        $this->selectTotalHits();
        $this->sortDocuments();
        $this->selectDistance();
        $this->applyDistinct();
        $this->limitPagination();

        $showAllAttributes = \in_array('*', $this->queryParameters->getAttributesToRetrieve(), true);
        $attributesToRetrieve = array_flip($this->queryParameters->getAttributesToRetrieve());

        $hits = [];

        foreach ($this->query()->iterateAssociative() as $result) {
            $document = Util::decodeJson($result['document']);

            foreach ($result as $k => $v) {
                if (str_starts_with($k, self::DISTANCE_ALIAS)) {
                    $document['_geoDistance(' . str_replace(self::DISTANCE_ALIAS . '_', '', $k) . ')'] = (int) round((float) $v);
                }
            }

            $hit = $showAllAttributes ? $document : array_intersect_key($document, $attributesToRetrieve);

            if ($this->queryParameters instanceof SearchParameters && $this->queryParameters->showRankingScore()) {
                $hit['_rankingScore'] = \array_key_exists(self::RELEVANCE_ALIAS, $result) ?
                    round($result[self::RELEVANCE_ALIAS], 5) : 0.0;
            }

            $this->formatHit($hit, $result, $tokensIncludingStopwords);

            $hits[] = $hit;
        }

        $totalHits = $result['totalHits'] ?? 0;
        $hitsPerPage = $this->queryBuilder->getMaxResults() ?? 0;
        $totalPages = $hitsPerPage === 0 ? 0 : ((int) ceil($totalHits / $hitsPerPage));
        $currentPage = $hitsPerPage === 0 ? 0 : ((int) floor($this->queryBuilder->getFirstResult() / $hitsPerPage) + 1);
        $end = (int) floor(microtime(true) * 1000);

        $resultClass = $this->queryParameters instanceof SearchParameters ? SearchResult::class : BrowseResult::class;

        $resultObject = new $resultClass(
            $hits,
            $this->createAnalyzedQuery($tokens),
            $end - $start,
            $hitsPerPage,
            $currentPage,
            $totalPages,
            $totalHits
        );

        if ($resultObject instanceof SearchResult) {
            return $this->addFacetsToSearchResult($resultObject, $result ?? []);
        }

        return $resultObject;
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

    public function getQueryParameters(): AbstractQueryParameters
    {
        return $this->queryParameters;
    }

    public function getSorting(): Sorting
    {
        return $this->sorting;
    }

    public function getTokens(): TokenCollection
    {
        if ($this->tokens instanceof TokenCollection) {
            return $this->tokens;
        }

        if ($this->queryParameters->getQuery() === '') {
            return $this->tokens = new TokenCollection();
        }

        return $this->tokens = $this->engine->getTokenizer()
            ->tokenize(
                $this->queryParameters->getQuery(),
                $this->engine->getConfiguration()->getMaxQueryTokens(),
            )->withoutStopwords($this->engine->getStopWords(), true);
    }

    public function hasCTE(string $cteName): bool
    {
        return isset($this->ctesByName[$cteName]);
    }

    private function addFacets(): void
    {
        if (!$this->queryParameters instanceof SearchParameters) {
            return;
        }

        $facets = array_intersect($this->queryParameters->getFacets(), $this->engine->getIndexInfo()->getFilterableAttributes());

        if ($facets === []) {
            return;
        }

        $buildCommonQueryBuilder = function (string $attribute, string $facetAlias): QueryBuilder {
            $qb = $this->engine->getConnection()->createQueryBuilder();

            if ($this->engine->getIndexInfo()->isMultiFilterableAttribute($attribute)) {
                $this->addFromMultiAttributesAndJoinMatches($qb, $attribute);
            } else {
                $qb->from(IndexInfo::TABLE_NAME_DOCUMENTS, $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS));
                $qb->innerJoin(
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                    self::CTE_MATCHES,
                    self::CTE_MATCHES,
                    sprintf(
                        '%s.document_id = %s.id',
                        self::CTE_MATCHES,
                        $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS)
                    )
                );
            }

            // Make sure null and empty values are not considered (MAX() would probably prefer those)
            $qb->andWhere($facetAlias . '!= ' . $this->queryBuilder->createNamedParameter(LoupeTypes::VALUE_NULL));
            $qb->andWhere($facetAlias . '!= ' . $this->queryBuilder->createNamedParameter(LoupeTypes::VALUE_EMPTY));

            $qb->setMaxResults(100); // Limit the number of facet values

            return $qb;
        };

        $addFacetCte = function (string $cteName, QueryBuilder $qb): void {
            $this->addCTE(new Cte($cteName, ['facet_group', 'facet_value'], $qb));
            $this->queryBuilder->addSelect(sprintf("(SELECT GROUP_CONCAT(facet_group || ':' || facet_value) FROM %s) AS %s", $cteName, $cteName));
        };

        foreach ($facets as $facet) {
            $isNumeric = $this->engine->getIndexInfo()->isNumericAttribute($facet);

            if ($this->engine->getIndexInfo()->isMultiFilterableAttribute($facet)) {
                $facetAlias = sprintf(
                    '%s.%s',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES),
                    $isNumeric ? 'numeric_value' : 'string_value',
                );

                $commonQb = $buildCommonQueryBuilder($facet, $facetAlias);

                // Count facet, always needed
                $qb = clone $commonQb;
                $qb->select($facetAlias, sprintf('COUNT(DISTINCT %s.document)', $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS)));
                $qb->groupBy($facetAlias);
                $addFacetCte(self::FACET_ALIAS_COUNT_PREFIX . $facet, $qb);

                // MinMax facet for numeric fields
                if ($isNumeric) {
                    $qb = clone $commonQb;
                    $qb->select(sprintf('MIN(%s)', $facetAlias), sprintf('MAX(%s)', $facetAlias));
                    $addFacetCte(self::FACET_ALIAS_MIN_MAX_PREFIX . $facet, $qb);
                }
            } else {
                $facetAlias = sprintf(
                    '%s.%s',
                    $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                    $facet,
                );

                $commonQb = $buildCommonQueryBuilder($facet, $facetAlias);

                // Count facet, always needed
                $qb = clone $commonQb;
                $qb->select($facetAlias, 'COUNT(*)');
                $qb->groupBy($facetAlias);
                $addFacetCte(self::FACET_ALIAS_COUNT_PREFIX . $facet, $qb);

                // MinMax facet for numeric fields
                if ($isNumeric) {
                    $qb = clone $commonQb;
                    $qb->select(sprintf('MIN(%s)', $facetAlias), sprintf('MAX(%s)', $facetAlias));
                    $addFacetCte(self::FACET_ALIAS_MIN_MAX_PREFIX . $facet, $qb);
                }
            }
        }
    }

    /**
     * @param array<mixed> $result
     */
    private function addFacetsToSearchResult(SearchResult $searchResult, array $result): SearchResult
    {
        $facetDistribution = [];
        $facetStats = [];

        foreach ($result as $column => $value) {
            if (str_starts_with($column, self::FACET_ALIAS_COUNT_PREFIX)) {
                $attribute = (string) preg_replace('/^' . self::FACET_ALIAS_COUNT_PREFIX . '(' . Configuration::ATTRIBUTE_NAME_RGXP . ')$/', '$1', $column);
                $isBoolean = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute) === LoupeTypes::TYPE_BOOLEAN;

                // No matches
                if ($value === null) {
                    $facetDistribution[$attribute] = [];
                    continue;
                }

                $items = explode(',', $value);
                foreach ($items as $item) {
                    $pos = strrpos($item, ':');

                    if ($pos === false) {
                        continue;
                    }

                    $group = substr($item, 0, $pos);
                    $value = substr($item, $pos + 1);

                    if ($isBoolean) {
                        $group = $group === '1.0' ? 'true' : 'false';
                    }

                    if (\in_array($group, [LoupeTypes::VALUE_EMPTY, LoupeTypes::VALUE_NULL], true)) {
                        continue;
                    }

                    $facetDistribution[$attribute][$group] = (int) $value;
                }
            }

            if (str_starts_with($column, self::FACET_ALIAS_MIN_MAX_PREFIX)) {
                $attribute = (string) preg_replace('/^' . self::FACET_ALIAS_MIN_MAX_PREFIX . '(' . Configuration::ATTRIBUTE_NAME_RGXP . ')$/', '$1', $column);

                // No matches
                if ($value === null) {
                    $facetStats[$attribute] = [];
                    continue;
                }

                [$min, $max] = explode(':', $value);
                $facetStats[$attribute]['min'] = (float) $min;
                $facetStats[$attribute]['max'] = (float) $max;
            }
        }

        if ($facetDistribution !== []) {
            $searchResult = $searchResult->withFacetDistribution($facetDistribution);
        }

        if ($facetStats !== []) {
            $searchResult = $searchResult->withFacetStats($facetStats);
        }

        return $searchResult;
    }

    private function addPositionsForFormatting(TokenCollection $tokenCollection): void
    {
        if (!$this->askedForFormattingOrMatchesPosition()) {
            return;
        }

        foreach ($tokenCollection->all() as $token) {
            $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENT_MATCHES_PREFIX, $token);

            $this->queryBuilder->addSelect(sprintf(
                "(SELECT GROUP_CONCAT(attribute || ':' || position) FROM %s WHERE %s.id = %s.document) AS %s",
                $cteName,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS),
                $cteName,
                self::MATCH_POSITION_INFO_PREFIX . $token->getId(),
            ));

            $this->queryBuilder
                ->innerJoin(
                    self::CTE_MATCHES,
                    self::CTE_MATCHES,
                    $cteName,
                    sprintf(
                        '%s.document_id = %s.document_id',
                        $cteName,
                        self::CTE_MATCHES
                    )
                );
        }
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

        if ($this->needsTypoCount()) {
            $cteSelectQb->addSelect(sprintf(
                'loupe_levensthein(%s.term, %s, %s) AS typos',
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                $this->createNamedParameter($token->getTerm()),
                $this->engine->getConfiguration()->getTypoTolerance()->firstCharTypoCountsDouble() ? 'true' : 'false'
            ));
            $cteSelectQb->innerJoin(
                $termsDocumentsAlias,
                IndexInfo::TABLE_NAME_TERMS,
                $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS),
                sprintf('%s.id = %s.term', $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS), $termsDocumentsAlias)
            );
        } else {
            $cteSelectQb->addSelect('0 AS typos');
        }

        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);

        // Get documents that match any of our terms
        $documentConditions = [];
        foreach ($this->getTokens()->all() as $otherToken) {
            $cteName = $this->getCTENameForToken(self::CTE_TERM_DOCUMENTS_PREFIX, $otherToken);
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

        if (['*'] !== $this->queryParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->createNamedParameter($this->queryParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
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

    private function addTermDocumentsCTE(Token $token): void
    {
        // No term matches CTE -> no term documents CTE
        $termMatchesCTE = $this->getCTENameForToken(self::CTE_TERM_MATCHES_PREFIX, $token);

        if (!$this->hasCTE($termMatchesCTE)) {
            return;
        }

        $termsDocumentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS);

        $cteSelectQb = $this->engine->getConnection()->createQueryBuilder();
        $cteSelectQb->select($termsDocumentsAlias . '.document')->distinct();

        $cteSelectQb->from(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, $termsDocumentsAlias);
        $cteSelectQb->where(sprintf('%s.term IN (SELECT id FROM %s)', $termsDocumentsAlias, $termMatchesCTE));

        if (['*'] !== $this->queryParameters->getAttributesToSearchOn()) {
            $cteSelectQb->andWhere(sprintf(
                $termsDocumentsAlias . '.attribute IN (%s)',
                $this->createNamedParameter($this->queryParameters->getAttributesToSearchOn(), ArrayParameterType::STRING)
            ));
        }

        // Only apply max total hits to search queries
        if ($this->queryParameters instanceof SearchParameters) {
            $cteSelectQb->setMaxResults($this->engine->getConfiguration()->getMaxTotalHits());
        }

        $this->addCTE(new Cte(
            $this->getCTENameForToken(self::CTE_TERM_DOCUMENTS_PREFIX, $token),
            ['document'],
            $cteSelectQb
        ));
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
                    $this->createNamedParameter($token->getTerm() . '%')
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

    private function applyDistinct(): void
    {
        if (!$this->queryParameters instanceof SearchParameters) {
            return;
        }

        $distinct = $this->queryParameters->getDistinct();

        if ($distinct === null) {
            return;
        }

        if (!\in_array($distinct, $this->engine->getIndexInfo()->getSingleFilterableAttributes(), true)) {
            throw InvalidSearchParametersException::distinctAttributeMustBeASingleFilterableAttribute();
        }

        $documentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $this->queryBuilder
            ->addSelect($documentsAlias . '.' . $distinct)
            ->groupBy($documentsAlias . '.' . $distinct);
    }

    private function askedForFormattingOrMatchesPosition(): bool
    {
        if (!$this->queryParameters instanceof SearchParameters) {
            return false;
        }
        if ($this->queryParameters->getAttributesToHighlight() !== []) {
            return true;
        }

        if ($this->queryParameters->getAttributesToCrop() !== []) {
            return true;
        }

        return $this->queryParameters->showMatchesPosition();
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

        foreach ($tokenCollection->phraseGroups() as $tokenOrPhrase) {
            $statements = [];
            foreach ($tokenOrPhrase->all() as $token) {
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
            return $this->queryParameters->getQuery();
        }

        $query = mb_substr($this->queryParameters->getQuery(), 0, $lastToken->getStartPosition() + $lastToken->getLength());

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
            $this->createNamedParameter($term),
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
        $termParameter = $this->createNamedParameter($term);
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
                $this->createNamedParameter($term . '%')
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
        $qbMatches->select('document_id')->distinct();

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

        $this->addCTE(new Cte(self::CTE_MATCHES, ['document_id'], $qbMatches));
    }

    /**
     * @param array<string, array<int>>|null $matchPositionInfo
     */
    private function formatAttributeForHit(string $attribute, string $value, TokenCollection $queryTerms, FormatterOptions $attributeOptions, ?array $matchPositionInfo = null): FormatterResult
    {
        if ($matchPositionInfo === null) {
            return $this->engine->getFormatter()->format($value, $queryTerms, $attributeOptions);
        }

        if (!isset($matchPositionInfo[$attribute])) {
            return new FormatterResult($value, new TokenCollection());
        }

        $matches = new TokenCollection();

        foreach ($this->engine->getTokenizer()->tokenize($value)->all() as $i => $token) {
            if (\in_array($i + 1, $matchPositionInfo[$attribute], true)) {
                $matches->add($token);
            }
        }

        return $this->engine->getFormatter()->format($value, $queryTerms, $attributeOptions, $matches);
    }

    /**
     * @param array<mixed> $hit
     * @param array<mixed> $queryResult
     */
    private function formatHit(array &$hit, array $queryResult, TokenCollection $queryTerms): void
    {
        if (!$this->queryParameters instanceof SearchParameters) {
            return;
        }

        $searchableAttributes = ['*'] === $this->engine->getConfiguration()->getSearchableAttributes()
            ? array_keys($hit)
            : $this->engine->getConfiguration()->getSearchableAttributes();
        $attributesToCrop = ['*'] === $this->queryParameters->getAttributesToCrop()
            ? array_keys($hit)
            : array_keys($this->queryParameters->getAttributesToCrop());
        $attributesToHighlight = ['*'] === $this->queryParameters->getAttributesToHighlight()
            ? array_keys($hit)
            : $this->queryParameters->getAttributesToHighlight();

        $options = (new FormatterOptions())
            ->withCropLength($this->queryParameters->getCropLength())
            ->withCropMarker($this->queryParameters->getCropMarker())
            ->withHighlightStartTag($this->queryParameters->getHighlightStartTag())
            ->withHighlightEndTag($this->queryParameters->getHighlightEndTag())
        ;

        $requiresFormatting = \count($attributesToCrop) > 0 || \count($attributesToHighlight) > 0;
        $showMatchesPosition = $this->queryParameters->showMatchesPosition();

        if (!$requiresFormatting && !$showMatchesPosition) {
            return;
        }

        $matchPositionInfo = [];

        foreach ($queryResult as $key => $value) {
            // Need to check for null because it might be that there was no match for a given term in this document
            if (str_starts_with($key, self::MATCH_POSITION_INFO_PREFIX) && $value !== null) {
                $documentMatches = explode(',', $value);
                foreach ($documentMatches as $documentMatch) {
                    $attributeMatches = explode(':', $documentMatch);
                    $matchPositionInfo[$attributeMatches[0]][] = (int) $attributeMatches[1];
                }
            }
        }

        // No match info, this should not happen (otherwise, why would it be a hit?) but defensive programming, I guess
        if ($matchPositionInfo === []) {
            return;
        }

        $formatted = $hit;
        $matchesPosition = [];

        foreach ($searchableAttributes as $attribute) {
            // Do not include any attribute not required by the result (limited by attributesToRetrieve)
            if (!isset($hit[$attribute])) {
                continue;
            }

            $attributeOptions = $options;

            if (\in_array($attribute, $attributesToCrop, true)) {
                $attributeOptions = $attributeOptions->withEnableCrop();

                if (isset($this->queryParameters->getAttributesToCrop()[$attribute])) {
                    $attributeOptions = $attributeOptions->withCropLength($this->queryParameters->getAttributesToCrop()[$attribute]);
                }
            }

            if (\in_array($attribute, $attributesToHighlight, true)) {
                $attributeOptions = $attributeOptions->withEnableHighlight();
            }

            if (\is_array($formatted[$attribute])) {
                foreach ($formatted[$attribute] as $key => $value) {
                    // Do not pass along match positions as we don't have reliable information about the position of matches in arrays
                    $formatterResult = $this->formatAttributeForHit($attribute, (string) $value, $queryTerms, $attributeOptions);

                    if ($showMatchesPosition && $formatterResult->hasMatches()) {
                        $matchesPosition[$attribute] ??= [];
                        $matchesPosition[$attribute][$key] = $formatterResult->getMatchesArray();
                    }

                    if ($requiresFormatting) {
                        $formatted[$attribute][$key] = $formatterResult->getFormattedText();
                    }
                }
            } else {
                $formatterResult = $this->formatAttributeForHit($attribute, (string) $formatted[$attribute], $queryTerms, $attributeOptions, $matchPositionInfo);

                if ($showMatchesPosition && $formatterResult->hasMatches()) {
                    $matchesPosition[$attribute] = $formatterResult->getMatchesArray();
                }

                if ($requiresFormatting) {
                    $formatted[$attribute] = $formatterResult->getFormattedText();
                }
            }
        }

        if ($requiresFormatting) {
            $hit['_formatted'] = $formatted;
        }

        if ($showMatchesPosition) {
            $hit['_matchesPosition'] = $matchesPosition;
        }
    }

    private function limitPagination(): void
    {
        $maxTotalHits = $this->engine->getConfiguration()->getMaxTotalHits();

        $offset = $this->queryParameters->getOffset();
        $limit = $this->queryParameters->getLimit();

        if ($this->queryParameters->getHitsPerPage() !== null || $this->queryParameters->getPage() !== null) {
            $limit = $this->queryParameters->getHitsPerPage() ?? SearchParameters::MAX_LIMIT;
            $offset = (($this->queryParameters->getPage() ?? 1) - 1) * $limit;
        }

        $limit = min($limit, $maxTotalHits);
        $offset = min($offset, $maxTotalHits - $limit);

        $this->queryBuilder->setFirstResult($offset);
        $this->queryBuilder->setMaxResults($limit);
    }

    /**
     * If typo tolerance is disabled or neither, exactness nor typo are part of the ranking rules, we can omit
     * calculating the info for better performance.
     */
    private function needsTypoCount(): bool
    {
        $configuration = $this->engine->getConfiguration();

        if ($configuration->getTypoTolerance()->isDisabled()) {
            return false;
        }

        return \in_array('exactness', $configuration->getRankingRules(), true) || \in_array('typo', $configuration->getRankingRules(), true);
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
        $this->addTermDocumentsCTEs($tokenCollection);
        $this->addTermDocumentMatchesCTEs($tokenCollection);
    }

    private function selectDistance(): void
    {
        foreach ($this->queryParameters->getAttributesToRetrieve() as $attribute) {
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
        // Only apply max total hits to search queries
        if ($this->queryParameters instanceof SearchParameters) {
            $select = sprintf('MIN(%d, COUNT() OVER()) AS totalHits', $this->engine->getConfiguration()->getMaxTotalHits());
        } else {
            $select = 'COUNT() OVER() AS totalHits';
        }

        $this->queryBuilder->addSelect($select);
    }

    private function sortDocuments(): void
    {
        $this->sorting->applySorters($this);
    }
}
