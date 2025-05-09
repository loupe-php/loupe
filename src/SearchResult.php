<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Internal\Search\AbstractQueryResult;

final class SearchResult extends AbstractQueryResult
{
    /**
     * @var array<string, array<string, int>>|null
     */
    private ?array $facetDistribution = null;

    /**
     * @var array<string, array<string, float>>|null
     */
    private ?array $facetStats = null;

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
        $array = parent::toArray();

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
