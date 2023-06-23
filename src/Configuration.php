<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Config\TypoTolerance;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Internal\LoupeTypes;

final class Configuration
{
    public const GEO_ATTRIBUTE_NAME = '_geo';

    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    private array $filterableAttributes = [];

    private string $primaryKey = 'id';

    private array $searchableAttributes = ['*'];

    private array $sortableAttributes = [];

    private TypoTolerance $typoTolerance;

    public function __construct()
    {
        $this->typoTolerance = new TypoTolerance();
    }

    public function getFilterableAndSortableAttributes(): array
    {
        return array_unique(array_merge($this->filterableAttributes, $this->sortableAttributes));
    }

    public function getFilterableAttributes(): array
    {
        return $this->filterableAttributes;
    }

    // TODO: REMOVE ME
    public function getHash(): string
    {
        return sha1(LoupeTypes::convertToString(get_object_vars($this)));
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

        $clone = clone $this;
        $clone->filterableAttributes = $filterableAttributes;

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

        $clone = clone $this;
        $clone->searchableAttributes = $searchableAttributes;

        return $clone;
    }

    public function withSortableAttributes(array $sortableAttributes): self
    {
        self::validateAttributeNames($sortableAttributes);

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
