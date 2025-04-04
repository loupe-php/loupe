<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Exception\InvalidSearchParametersException;

final class SearchParameters
{
    public const MAX_HITS_PER_PAGE = 1000;

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

    private string $filter = '';

    private string $highlightEndTag = '</em>';

    private string $highlightStartTag = '<em>';

    private int $hitsPerPage = 20;

    private int $page = 1;

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

        $hash[] = json_encode($this->getAttributesToHighlight());
        $hash[] = json_encode($this->getHighlightStartTag());
        $hash[] = json_encode($this->getHighlightEndTag());
        $hash[] = json_encode($this->getAttributesToRetrieve());
        $hash[] = json_encode($this->getAttributesToSearchOn());
        $hash[] = json_encode($this->getFilter());
        $hash[] = json_encode($this->getHitsPerPage());
        $hash[] = json_encode($this->getPage());
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

    public function getHitsPerPage(): int
    {
        return $this->hitsPerPage;
    }

    public function getPage(): int
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

    public function withFilter(string $filter): self
    {
        $clone = clone $this;
        $clone->filter = $filter;

        return $clone;
    }

    public function withHitsPerPage(int $hitsPerPage): self
    {
        if ($hitsPerPage > self::MAX_HITS_PER_PAGE) {
            throw InvalidSearchParametersException::maxHitsPerPage();
        }

        $clone = clone $this;
        $clone->hitsPerPage = $hitsPerPage;

        return $clone;
    }

    public function withPage(int $page): self
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
