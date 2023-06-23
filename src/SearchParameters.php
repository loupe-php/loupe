<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Internal\Search\Sorting\Relevance;

final class SearchParameters
{
    private array $attributesToHighlight = [];

    private array $attributesToRetrieve = ['*'];

    private string $filter = '';

    private int $hitsPerPage = 20;

    private int $page = 1;

    private string $query = '';

    private bool $showMatchesPosition = false;

    private array $sort = [Relevance::RELEVANCE_ALIAS . ':desc'];

    public static function create(): self
    {
        return new self();
    }

    public function getAttributesToHighlight(): array
    {
        return $this->attributesToHighlight;
    }

    public function getAttributesToRetrieve(): array
    {
        return $this->attributesToRetrieve;
    }

    public function getFilter(): string
    {
        return $this->filter;
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

    public function getSort(): array
    {
        return $this->sort;
    }

    public function showMatchesPosition(): bool
    {
        return $this->showMatchesPosition;
    }

    public function withAttributesToHighlight(array $attributesToHighlight): self
    {
        $clone = clone $this;
        $clone->attributesToHighlight = $attributesToHighlight;

        return $clone;
    }

    public function withAttributesToRetrieve(array $attributesToRetrieve): self
    {
        $clone = clone $this;
        $clone->attributesToRetrieve = $attributesToRetrieve;

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

    public function withShowMatchesPosition(bool $showMatchesPosition): self
    {
        $clone = clone $this;
        $clone->showMatchesPosition = $showMatchesPosition;

        return $clone;
    }

    public function withSort(array $sort): self
    {
        $clone = clone $this;
        $clone->sort = $sort;

        return $clone;
    }
}
