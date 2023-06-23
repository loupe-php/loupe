<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Internal\LoupeTypes;
use voku\helper\UTF8;

final class Configuration
{
    public const GEO_ATTRIBUTE_NAME = '_geo';

    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    public array $filterableAttributes = [];

    public string $primaryKey = 'id';

    public array $searchableAttributes = ['*'];

    public array $sortableAttributes = [];

    public static function fromArray(array $configuration): self
    {
        $parameters = new self();

        foreach ($configuration as $k => $v) {
            $parameters->{$k} = $v;
        }

        return $parameters;
    }

    public function getFilterableAndSortableAttributes(): array
    {
        return array_unique(array_merge($this->filterableAttributes, $this->sortableAttributes));
    }

    public function getHash(): string
    {
        return sha1(LoupeTypes::convertToString(get_object_vars($this)));
    }

    public function getLevenshteinDistanceForTerm(string $term): int
    {
        $termLength = (int) UTF8::strlen($term);

        return match (true) {
            $termLength >= 9 => 2,
            $termLength >= 5 => 2,
            default => 0
        };
    }

    public function validate(): void
    {
        if (['*'] !== $this->searchableAttributes) {
            foreach ($this->searchableAttributes as $searchableAttribute) {
                self::validateAttributeName($searchableAttribute);
            }
        }

        foreach ($this->filterableAttributes as $searchableAttribute) {
            self::validateAttributeName($searchableAttribute);
        }

        foreach ($this->sortableAttributes as $searchableAttribute) {
            self::validateAttributeName($searchableAttribute);
        }
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
}
