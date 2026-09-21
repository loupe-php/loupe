<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\BrowseResult;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Util;
use Loupe\Loupe\SearchParameters;

class Browser
{
    public function __construct(
        private readonly Engine $engine,
        private readonly Parser $filterParser,
        private readonly BrowseParameters $parameters,
    ) {
    }

    public function fetchResult(): BrowseResult
    {
        if (!$this->canScanDocuments()) {
            return (new Searcher($this->engine, $this->filterParser, $this->parameters))->fetchResult();
        }

        return $this->engine->getConnection()->transactional(fn (): BrowseResult => $this->scanDocuments());
    }

    private function canScanDocuments(): bool
    {
        return '' === $this->parameters->getQuery()
            && '' === $this->parameters->getFilter()
            && $this->parameters->getOffset() >= 0
            && $this->parameters->getLimit() >= 0;
    }

    /**
     * @param array<string> $attributesToRetrieve
     *
     * @return array<array<string, mixed>>
     */
    private function fetchHits(QueryBuilder $queryBuilder, string $documentsAlias, array $attributesToRetrieve): array
    {
        $primaryKey = $this->engine->getConfiguration()->getPrimaryKey();

        if ([$primaryKey] === $attributesToRetrieve) {
            $ids = $queryBuilder
                ->select($this->primaryKeyExpression($documentsAlias, $primaryKey))
                ->setParameter('__loupe_pk_path', '$.'.$primaryKey)
                ->fetchFirstColumn()
            ;

            return array_map(static fn (mixed $id): array => [$primaryKey => $id], $ids);
        }

        $documents = $queryBuilder->select($documentsAlias.'._document')->executeQuery()->iterateColumn();
        $hits = [];

        if (\in_array('*', $attributesToRetrieve, true)) {
            foreach ($documents as $document) {
                $hits[] = Util::decodeJson($document);
            }

            return $hits;
        }

        $keep = array_flip($attributesToRetrieve);

        foreach ($documents as $document) {
            $hits[] = array_intersect_key(Util::decodeJson($document), $keep);
        }

        return $hits;
    }

    private function primaryKeyExpression(string $documentsAlias, string $primaryKey): string
    {
        return match ($this->engine->getIndexInfo()->getDocumentSchema()[$primaryKey] ?? null) {
            LoupeTypes::TYPE_STRING => $documentsAlias.'._user_id',
            LoupeTypes::TYPE_NUMBER => \sprintf('CAST(%s._user_id AS NUMERIC)', $documentsAlias),
            default => \sprintf('json_extract(%s._document, :__loupe_pk_path)', $documentsAlias),
        };
    }

    private function scanDocuments(): BrowseResult
    {
        $start = (int) floor(microtime(true) * 1000);

        $limit = $this->parameters->getLimit();
        $offset = $this->parameters->getOffset();

        if (null !== $this->parameters->getHitsPerPage() || null !== $this->parameters->getPage()) {
            $limit = $this->parameters->getHitsPerPage() ?? SearchParameters::MAX_LIMIT;
            $offset = (($this->parameters->getPage() ?? 1) - 1) * $limit;
        }

        $documentsAlias = $this->engine->getIndexInfo()->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);
        $queryBuilder = $this->engine->getConnection()->createQueryBuilder()
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS, $documentsAlias)
            ->orderBy($documentsAlias.'._id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
        ;

        $hits = $this->fetchHits($queryBuilder, $documentsAlias, $this->parameters->getAttributesToRetrieve());
        $totalHits = [] === $hits ? 0 : $this->engine->countDocuments();
        $totalPages = 0 === $limit ? 0 : (int) ceil($totalHits / $limit);
        $currentPage = 0 === $limit ? 0 : (int) floor($offset / $limit) + 1;

        return new BrowseResult(
            $hits,
            $this->parameters->getQuery(),
            (int) floor(microtime(true) * 1000) - $start,
            $limit,
            $currentPage,
            $totalPages,
            $totalHits,
        );
    }
}
