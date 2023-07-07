<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Exception\InvalidConfigurationException;

final class Configuration
{
    public const GEO_ATTRIBUTE_NAME = '_geo';

    public const MAX_ATTRIBUTE_NAME_LENGTH = 64;

    private array $filterableAttributes = [];

    private int $maxQueryTokens = 10;

    private string $primaryKey = 'id';

    private array $searchableAttributes = ['*'];

    private array $sortableAttributes = [];

    private TypoTolerance $typoTolerance;

    public function __construct()
    {
        $this->typoTolerance = new TypoTolerance();
    }

    public static function create(): self
    {
        return new self();
    }

    public function getDocumentSchemaRelevantAttributes(): array
    {
        return array_unique(array_merge(
            [$this->getPrimaryKey()],
            [self::GEO_ATTRIBUTE_NAME],
            $this->getSearchableAttributes(),
            $this->getFilterableAndSortableAttributes()
        ));
    }

    public function getFilterableAndSortableAttributes(): array
    {
        return array_unique(array_merge($this->filterableAttributes, $this->sortableAttributes));
    }

    public function getFilterableAttributes(): array
    {
        return $this->filterableAttributes;
    }

    /**
     * Returns a hash of all the settings that are relevant during the indexing process. If anything changes in the
     * configuration, a reindex of data is needed.
     */
    public function getIndexHash(): string
    {
        $hash = [];

        $hash[] = json_encode($this->getPrimaryKey());
        $hash[] = json_encode($this->getSearchableAttributes());
        $hash[] = json_encode($this->getFilterableAttributes());
        $hash[] = json_encode($this->getSortableAttributes());

        $hash[] = $this->getTypoTolerance()->isDisabled() ? 'disabled' : 'enabled';
        $hash[] = $this->getTypoTolerance()->getAlphabetSize();
        $hash[] = $this->getTypoTolerance()->getIndexLength();

        return hash('sha256', implode(';', $hash));
    }

    public function getMaxQueryTokens(): int
    {
        return $this->maxQueryTokens;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getSearchableAttributes(): array
    {
        return $this->searchableAttributes;
    }

    public function getSortableAttributes(): array
    {
        return $this->sortableAttributes;
    }

    public function getTypoTolerance(): TypoTolerance
    {
        return $this->typoTolerance;
    }

    public static function validateAttributeName(string $name): void
    {
        if ($name === self::GEO_ATTRIBUTE_NAME) {
            return;
        }

        if (strlen($name) > self::MAX_ATTRIBUTE_NAME_LENGTH
            || ! preg_match('/^[a-zA-Z\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
        ) {
            throw InvalidConfigurationException::becauseInvalidAttributeName($name);
        }
    }

    public function withFilterableAttributes(array $filterableAttributes): self
    {
        self::validateAttributeNames($filterableAttributes);

        sort($filterableAttributes);

        $clone = clone $this;
        $clone->filterableAttributes = $filterableAttributes;

        return $clone;
    }

    public function withMaxQueryTokens(int $maxQueryTokens): self
    {
        $clone = clone $this;
        $clone->maxQueryTokens = $maxQueryTokens;

        return $clone;
    }

    public function withPrimaryKey(string $primaryKey): self
    {
        $clone = clone $this;
        $clone->primaryKey = $primaryKey;

        return $clone;
    }

    public function withSearchableAttributes(array $searchableAttributes): self
    {
        if (['*'] !== $searchableAttributes) {
            self::validateAttributeNames($searchableAttributes);
        }

        sort($searchableAttributes);

        $clone = clone $this;
        $clone->searchableAttributes = $searchableAttributes;

        return $clone;
    }

    public function withSortableAttributes(array $sortableAttributes): self
    {
        self::validateAttributeNames($sortableAttributes);

        sort($sortableAttributes);

        $clone = clone $this;
        $clone->sortableAttributes = $sortableAttributes;

        return $clone;
    }

    public function withTypoTolerance(TypoTolerance $tolerance): self
    {
        $clone = clone $this;
        $clone->typoTolerance = $tolerance;

        return $clone;
    }

    private static function validateAttributeNames(array $names): void
    {
        foreach ($names as $name) {
            self::validateAttributeName($name);
        }
    }
}
