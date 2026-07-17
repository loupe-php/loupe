<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

abstract class AbstractQueryResult
{
    /**
     * @param array<array<string, mixed>> $hits
     */
    public function __construct(
        private readonly array $hits,
        private readonly string $query,
        private readonly int $processingTimeMs,
        private readonly int $hitsPerPage,
        private readonly int $page,
        private readonly int $totalPages,
        private readonly int $totalHits,
    ) {
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getHits(): array
    {
        return $this->hits;
    }

    public function getHitsPerPage(): int
    {
        return $this->hitsPerPage;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getProcessingTimeMs(): int
    {
        return $this->processingTimeMs;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getTotalHits(): int
    {
        return $this->totalHits;
    }

    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * @return array{
     *     hits: array<array<string, mixed>>,
     *     query: string,
     *     processingTimeMs: int,
     *     hitsPerPage: int,
     *     page: int,
     *     totalPages: int,
     *     totalHits: int
     * }
     */
    public function toArray(): array
    {
        return [
            'hits' => $this->getHits(),
            'query' => $this->getQuery(),
            'processingTimeMs' => $this->getProcessingTimeMs(),
            'hitsPerPage' => $this->getHitsPerPage(),
            'page' => $this->getPage(),
            'totalPages' => $this->getTotalPages(),
            'totalHits' => $this->getTotalHits(),
        ];
    }
}
