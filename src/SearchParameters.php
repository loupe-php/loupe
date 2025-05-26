<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Internal\Search\AbstractQueryParameters;

final class SearchParameters extends AbstractQueryParameters
{
    /**
     * @var array<string,int>
     */
    private array $attributesToCrop = [];

    /**
     * @var array<string>
     */
    private array $attributesToHighlight = [];

    private int $cropLength = 50;

    private string $cropMarker = '…';

    private ?string $distinct = null;

    /**
     * @var array<string>
     */
    private array $facets = [];

    private string $highlightEndTag = '</em>';

    private string $highlightStartTag = '<em>';

    private float $rankingScoreThreshold = 0.0;

    private bool $showMatchesPosition = false;

    private bool $showRankingScore = false;

    /**
     * @var array<string>
     */
    private array $sort = [Internal\Search\Searcher::RELEVANCE_ALIAS . ':desc'];

    public static function create(): static
    {
        return new self();
    }

    /**
     * @param array{
     *     attributesToCrop?: array<string>|array<string,int>,
     *     attributesToHighlight?: array<string>,
     *     attributesToRetrieve?: array<string>,
     *     attributesToSearchOn?: array<string>,
     *     facets?: array<string>,
     *     filter?: string,
     *     highlightEndTag?: string,
     *     highlightStartTag?: string,
     *     hitsPerPage?: ?int,
     *     page?: ?int,
     *     offset?: int,
     *     limit?: int,
     *     query?: string,
     *     rankingScoreThreshold?: float,
     *     showMatchesPosition?: bool,
     *     showRankingScore?: bool,
     *     distinct?: ?string,
     *     sort?: array<string>
     * } $data
     */
    public static function fromArray(array $data): static
    {
        $instance = parent::fromArray($data);

        if (isset($data['attributesToCrop'])) {
            $instance = $instance->withAttributesToCrop(
                $data['attributesToCrop'],
                $data['cropLength'] ?? 50,
                $data['cropMarker'] ?? '…',
            );
        }

        if (isset($data['attributesToHighlight'])) {
            $instance = $instance->withAttributesToHighlight(
                $data['attributesToHighlight'],
                $data['highlightStartTag'] ?? '<em>',
                $data['highlightEndTag'] ?? '</em>',
            );
        }

        if (isset($data['facets'])) {
            $instance = $instance->withFacets($data['facets']);
        }

        if (isset($data['rankingScoreThreshold'])) {
            $instance = $instance->withRankingScoreThreshold($data['rankingScoreThreshold']);
        }

        if (isset($data['showMatchesPosition'])) {
            $instance = $instance->withShowMatchesPosition($data['showMatchesPosition']);
        }

        if (isset($data['showRankingScore'])) {
            $instance = $instance->withShowRankingScore($data['showRankingScore']);
        }

        if (isset($data['distinct'])) {
            $instance = $instance->withDistinct($data['distinct']);
        }

        if (isset($data['sort'])) {
            $instance = $instance->withSort($data['sort']);
        }

        return $instance;
    }

    /**
     * @return array<string,int>
     */
    public function getAttributesToCrop(): array
    {
        return $this->attributesToCrop;
    }

    /**
     * @return array<string>
     */
    public function getAttributesToHighlight(): array
    {
        return $this->attributesToHighlight;
    }

    public function getCropLength(): int
    {
        return $this->cropLength;
    }

    public function getCropMarker(): string
    {
        return $this->cropMarker;
    }

    public function getDistinct(): ?string
    {
        return $this->distinct;
    }

    /**
     * @return array<string>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    /**
     * Returns a hash of all the search settings. Use this if you want to cache results per request.
     */
    public function getHash(): string
    {
        $hash = [];

        $hash[] = json_encode($this->getAttributesToCrop());
        $hash[] = json_encode($this->getAttributesToHighlight());
        $hash[] = json_encode($this->getCropLength());
        $hash[] = json_encode($this->getCropMarker());
        $hash[] = json_encode($this->getHighlightEndTag());
        $hash[] = json_encode($this->getHighlightStartTag());
        $hash[] = json_encode($this->getAttributesToRetrieve());
        $hash[] = json_encode($this->getAttributesToSearchOn());
        $hash[] = json_encode($this->getFilter());
        $hash[] = json_encode($this->getHitsPerPage());
        $hash[] = json_encode($this->getPage());
        $hash[] = json_encode($this->getLimit());
        $hash[] = json_encode($this->getOffset());
        $hash[] = json_encode($this->getQuery());
        $hash[] = json_encode($this->showMatchesPosition());
        $hash[] = json_encode($this->showRankingScore());

        return hash('sha256', implode(';', $hash));
    }

    public function getHighlightEndTag(): string
    {
        return $this->highlightEndTag;
    }

    public function getHighlightStartTag(): string
    {
        return $this->highlightStartTag;
    }

    public function getRankingScoreThreshold(): float
    {
        return $this->rankingScoreThreshold;
    }

    /**
     * @return array<string>
     */
    public function getSort(): array
    {
        return $this->sort;
    }

    public function showMatchesPosition(): bool
    {
        return $this->showMatchesPosition;
    }

    public function showRankingScore(): bool
    {
        return $this->showRankingScore;
    }

    /**
     * @return array{
     *     attributesToCrop: array<string,int>,
     *     attributesToHighlight: array<string>,
     *     facets: array<string>,
     *     cropLength: int,
     *     cropMarker: string,
     *     filter: string,
     *     highlightEndTag: string,
     *     highlightStartTag: string,
     *     hitsPerPage: ?int,
     *     page: ?int,
     *     offset: int,
     *     limit: int,
     *     query: string,
     *     attributesToRetrieve: array<string>,
     *     attributesToSearchOn: array<string>,
     *     rankingScoreThreshold: float,
     *     showMatchesPosition: bool,
     *     showRankingScore: bool,
     *     distinct: ?string,
     *     sort: array<string>
     * }
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'attributesToCrop' => $this->attributesToCrop,
            'attributesToHighlight' => $this->attributesToHighlight,
            'facets' => $this->facets,
            'cropLength' => $this->cropLength,
            'cropMarker' => $this->cropMarker,
            'highlightEndTag' => $this->highlightEndTag,
            'highlightStartTag' => $this->highlightStartTag,
            'rankingScoreThreshold' => $this->rankingScoreThreshold,
            'showMatchesPosition' => $this->showMatchesPosition,
            'showRankingScore' => $this->showRankingScore,
            'distinct' => $this->distinct,
            'sort' => $this->sort,
        ]);
    }

    /**
     * @param array<string>|array<string,int> $attributesToCrop
     */
    public function withAttributesToCrop(
        array $attributesToCrop,
        int $cropLength = 50,
        string $cropMarker = '…',
    ): self {
        $clone = clone $this;

        $attributes = [];
        foreach ($attributesToCrop as $key => $attribute) {
            if (\is_string($key) && \is_int($attribute)) {
                $attributes[$key] = $attribute;
            } elseif (\is_string($attribute)) {
                $attributes[$attribute] = $cropLength;
            }
        }

        ksort($attributes);

        $clone->attributesToCrop = $attributes;
        $clone->cropMarker = $cropMarker;
        $clone->cropLength = $cropLength;

        return $clone;
    }

    /**
     * @param array<string> $attributesToHighlight
     */
    public function withAttributesToHighlight(
        array $attributesToHighlight,
        string $highlightStartTag = '<em>',
        string $highlightEndTag = '</em>',
    ): self {
        sort($attributesToHighlight);

        $clone = clone $this;
        $clone->attributesToHighlight = $attributesToHighlight;
        $clone->highlightStartTag = $highlightStartTag;
        $clone->highlightEndTag = $highlightEndTag;

        return $clone;
    }

    public function withDistinct(?string $distinct): self
    {
        $clone = clone $this;
        $clone->distinct = $distinct;
        return $clone;
    }

    /**
     * @param array<string> $facets
     */
    public function withFacets(array $facets): self
    {
        $clone = clone $this;
        $clone->facets = $facets;

        return $clone;
    }

    public function withRankingScoreThreshold(float $rankingScoreThreshold): self
    {
        $clone = clone $this;
        $clone->rankingScoreThreshold = $rankingScoreThreshold;

        return $clone;
    }

    public function withShowMatchesPosition(bool $showMatchesPosition): self
    {
        $clone = clone $this;
        $clone->showMatchesPosition = $showMatchesPosition;

        return $clone;
    }

    public function withShowRankingScore(bool $showRankingScore): self
    {
        $clone = clone $this;
        $clone->showRankingScore = $showRankingScore;

        return $clone;
    }

    /**
     * @param array<string> $sort
     */
    public function withSort(array $sort): self
    {
        $clone = clone $this;
        $clone->sort = $sort;

        return $clone;
    }
}
