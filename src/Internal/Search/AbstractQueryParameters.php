<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

use Loupe\Loupe\Exception\InvalidSearchParametersException;

abstract class AbstractQueryParameters
{
    public const MAX_HITS_PER_PAGE = 1000;

    /**
     * @var array<string>
     */
    private array $attributesToRetrieve = ['*'];

    /**
     * @var array<string>
     */
    private array $attributesToSearchOn = ['*'];

    private string $filter = '';

    private int $hitsPerPage = 20;

    private int $page = 1;

    private string $query = '';

    public static function create(): static
    {
        return new static();
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
     *     filter?: string,
     *     hitsPerPage?: int,
     *     page?: int,
     *     query?: string,
     *     attributesToRetrieve?: array<string>,
     *     attributesToSearchOn?: array<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new static();

        if (isset($data['filter'])) {
            $instance = $instance->withFilter($data['filter']);
        }

        if (isset($data['hitsPerPage'])) {
            $instance = $instance->withHitsPerPage($data['hitsPerPage']);
        }

        if (isset($data['page'])) {
            $instance = $instance->withPage($data['page']);
        }

        if (isset($data['query'])) {
            $instance = $instance->withQuery($data['query']);
        }

        if (isset($data['attributesToRetrieve'])) {
            $instance = $instance->withAttributesToRetrieve($data['attributesToRetrieve']);
        }

        if (isset($data['attributesToSearchOn'])) {
            $instance = $instance->withAttributesToSearchOn($data['attributesToSearchOn']);
        }

        return $instance;
    }

    public static function fromString(string $string): self
    {
        return self::fromArray(json_decode($string, true, 512, JSON_THROW_ON_ERROR));
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

    /**
     * @return array{
     *     filter: string,
     *     hitsPerPage: int,
     *     page: int,
     *     query: string,
     *     attributesToRetrieve: array<string>,
     *     attributesToSearchOn: array<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'filter' => $this->filter,
            'hitsPerPage' => $this->hitsPerPage,
            'page' => $this->page,
            'query' => $this->query,
            'attributesToRetrieve' => $this->attributesToRetrieve,
            'attributesToSearchOn' => $this->attributesToSearchOn,
        ];
    }

    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
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
}
