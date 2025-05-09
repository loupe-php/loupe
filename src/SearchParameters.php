<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Exception\InvalidSearchParametersException;

final class SearchParameters
{
    public const MAX_LIMIT = 1000;

    /**
     * @var array<string,int>
     */
    private array $attributesToCrop = [];

    /**
     * @var array<string>
     */
    private array $attributesToHighlight = [];

    /**
     * @var array<string>
     */
    private array $attributesToRetrieve = ['*'];

    /**
     * @var array<string>
     */
    private array $attributesToSearchOn = ['*'];

    private int $cropLength = 50;

    private string $cropMarker = '…';

    /**
     * @var array<string>
     */
    private array $facets = [];

    private string $filter = '';

    private string $highlightEndTag = '</em>';

    private string $highlightStartTag = '<em>';

    private ?int $hitsPerPage = null;

    private int $limit = 20;

    private int $offset = 0;

    private ?int $page = null;

    private string $query = '';

    private float $rankingScoreThreshold = 0.0;

    private bool $showMatchesPosition = false;

    private bool $showRankingScore = false;

    /**
     * @var array<string>
     */
    private array $sort = [Internal\Search\Searcher::RELEVANCE_ALIAS . ':desc'];

    public static function create(): self
    {
        return new self();
    }

    public static function escapeFilterValue(string|int|float|bool $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value) => (string) $value,
            default => "'" . str_replace("'", "''", $value) . "'"
        };
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
     *     sort?: array<string>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();

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

        if (isset($data['attributesToRetrieve'])) {
            $instance = $instance->withAttributesToRetrieve($data['attributesToRetrieve']);
        }

        if (isset($data['attributesToSearchOn'])) {
            $instance = $instance->withAttributesToSearchOn($data['attributesToSearchOn']);
        }

        if (isset($data['facets'])) {
            $instance = $instance->withFacets($data['facets']);
        }

        if (isset($data['filter'])) {
            $instance = $instance->withFilter($data['filter']);
        }

        if (isset($data['hitsPerPage'])) {
            $instance = $instance->withHitsPerPage($data['hitsPerPage']);
        }

        if (isset($data['page'])) {
            $instance = $instance->withPage($data['page']);
        }

        if (isset($data['offset'])) {
            $instance = $instance->withOffset((int) $data['offset']);
        }

        if (isset($data['limit'])) {
            $instance = $instance->withLimit((int) $data['limit']);
        }

        if (isset($data['query'])) {
            $instance = $instance->withQuery($data['query']);
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

        if (isset($data['sort'])) {
            $instance = $instance->withSort($data['sort']);
        }

        return $instance;
    }

    public static function fromString(string $string): self
    {
        return self::fromArray(json_decode($string, true, 512, JSON_THROW_ON_ERROR));
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

    /**
     * @return array<string>
     */
    public function getAttributesToRetrieve(): array
    {
        return $this->attributesToRetrieve;
    }

    /**
     * @return array<string>
     */
    public function getAttributesToSearchOn(): array
    {
        return $this->attributesToSearchOn;
    }

    public function getCropLength(): int
    {
        return $this->cropLength;
    }

    public function getCropMarker(): string
    {
        return $this->cropMarker;
    }

    /**
     * @return array<string>
     */
    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getFilter(): string
    {
        return $this->filter;
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

    public function getHitsPerPage(): ?int
    {
        return $this->hitsPerPage;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getPage(): ?int
    {
        return $this->page;
    }

    public function getQuery(): string
    {
        return $this->query;
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
     *     attributesToRetrieve: array<string>,
     *     attributesToSearchOn: array<string>,
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
     *     rankingScoreThreshold: float,
     *     showMatchesPosition: bool,
     *     showRankingScore: bool,
     *     sort: array<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'attributesToCrop' => $this->attributesToCrop,
            'attributesToHighlight' => $this->attributesToHighlight,
            'attributesToRetrieve' => $this->attributesToRetrieve,
            'attributesToSearchOn' => $this->attributesToSearchOn,
            'facets' => $this->facets,
            'cropLength' => $this->cropLength,
            'cropMarker' => $this->cropMarker,
            'filter' => $this->filter,
            'highlightEndTag' => $this->highlightEndTag,
            'highlightStartTag' => $this->highlightStartTag,
            'hitsPerPage' => $this->hitsPerPage,
            'page' => $this->page,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'query' => $this->query,
            'rankingScoreThreshold' => $this->rankingScoreThreshold,
            'showMatchesPosition' => $this->showMatchesPosition,
            'showRankingScore' => $this->showRankingScore,
            'sort' => $this->sort,
        ];
    }

    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
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

    /**
     * @param array<string> $attributesToRetrieve
     */
    public function withAttributesToRetrieve(array $attributesToRetrieve): self
    {
        sort($attributesToRetrieve);

        $clone = clone $this;
        $clone->attributesToRetrieve = $attributesToRetrieve;

        return $clone;
    }

    /**
     * @param array<string> $attributesToSearchOn
     */
    public function withAttributesToSearchOn(array $attributesToSearchOn): self
    {
        sort($attributesToSearchOn);

        $clone = clone $this;
        $clone->attributesToSearchOn = $attributesToSearchOn;

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

    public function withFilter(string $filter): self
    {
        $clone = clone $this;
        $clone->filter = $filter;

        return $clone;
    }

    public function withHitsPerPage(?int $hitsPerPage): self
    {
        if ($hitsPerPage !== null && $hitsPerPage > self::MAX_LIMIT) {
            throw InvalidSearchParametersException::maxLimit();
        }

        $clone = clone $this;
        $clone->hitsPerPage = $hitsPerPage;

        return $clone;
    }

    public function withLimit(int $limit): self
    {
        if ($limit > self::MAX_LIMIT) {
            throw InvalidSearchParametersException::maxLimit();
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function withOffset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    public function withPage(?int $page): self
    {
        $clone = clone $this;
        $clone->page = $page;

        return $clone;
    }

    public function withQuery(string $query): self
    {
        $clone = clone $this;
        $clone->query = $query;

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
