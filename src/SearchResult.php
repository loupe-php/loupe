<?php

declare(strict_types=1);

namespace Loupe\Loupe;

final class SearchResult
{
    /**
     * @var array<string, array<string, int>>|null
     */
    private ?array $facetDistribution = null;

    /**
     * @var array<string, array<string, float>>|null
     */
    private ?array $facetStats = null;

    /**
     * @param array<array<string, mixed>> $hits
     */
    public function __construct(
        private array $hits,
        private string $query,
        private int $processingTimeMs,
        private int $hitsPerPage,
        private int $page,
        private int $totalPages,
        private int $totalHits
    ) {
    }

    public static function createEmptyFromSearchParameters(SearchParameters $searchParameters): self
    {
        return new self(
            [],
            $searchParameters->getQuery(),
            0,
            $searchParameters->getHitsPerPage() ?? $searchParameters->getLimit(),
            1,
            0,
            0
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getFacetDistribution(): array
    {
        return $this->facetDistribution ?? [];
    }

    /**
     * @return array<string, array<string, float>>
     */
    public function getFacetStats(): array
    {
        return $this->facetStats ?? [];
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
     *     totalHits: int,
     *     facetDistribution?: array<string, array<string, int>>,
     *     facetStats?: array<string, array<string, float>>,
     * }
     */
    public function toArray(): array
    {
        $array = [
            'hits' => $this->getHits(),
            'query' => $this->getQuery(),
            'processingTimeMs' => $this->getProcessingTimeMs(),
            'hitsPerPage' => $this->getHitsPerPage(),
            'page' => $this->getPage(),
            'totalPages' => $this->getTotalPages(),
            'totalHits' => $this->getTotalHits(),
        ];

        if ($this->facetDistribution) {
            $array['facetDistribution'] = $this->facetDistribution;
        }

        if ($this->facetStats) {
            $array['facetStats'] = $this->facetStats;
        }

        return $array;
    }

    /**
     * @param array<string, array<string, int>> $facetDistribution
     */
    public function withFacetDistribution(array $facetDistribution): self
    {
        $clone = clone $this;
        $clone->facetDistribution = $facetDistribution;
        return $clone;
    }

    /**
     * @param array<string, array<string, float>> $facetStats
     */
    public function withFacetStats(array $facetStats): self
    {
        $clone = clone $this;
        $clone->facetStats = $facetStats;
        return $clone;
    }
}
