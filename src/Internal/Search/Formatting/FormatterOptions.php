<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\SearchParameters;

class FormatterOptions
{
    /**
     * @var array<string,int>
     */
    private array $attributesToCrop;

    /**
     * @var array<string>
     */
    private array $attributesToHighlight;

    /**
     * @param array<string> $searchableAttributes
     */
    public function __construct(
        private SearchParameters $searchParameters,
        private array $searchableAttributes
    ) {
        $this->attributesToHighlight = $this->searchParameters->getAttributesToHighlight();
        $this->attributesToCrop = $this->searchParameters->getAttributesToCrop();
    }

    public function getCropLength(): int
    {
        return $this->searchParameters->getCropLength();
    }

    public function getCropLengthForAttribute(string $attribute): int
    {
        return $this->attributesToCrop[$attribute] ?? $this->searchParameters->getCropLength();
    }

    public function getCropMarker(): string
    {
        return $this->searchParameters->getCropMarker();
    }

    public function getHighlightEndTag(): string
    {
        return $this->searchParameters->getHighlightEndTag();
    }

    public function getHighlightStartTag(): string
    {
        return $this->searchParameters->getHighlightStartTag();
    }

    public function requiresFormatting(): bool
    {
        return \count($this->attributesToCrop) > 0 || \count($this->attributesToHighlight) > 0;
    }

    public function shouldCropAttribute(string $attribute): bool
    {
        return array_key_exists($attribute, $this->attributesToCrop) ||
            array_key_exists('*', $this->attributesToCrop) && \in_array($attribute, $this->searchableAttributes);
    }

    public function shouldHighlightAttribute(string $attribute): bool
    {
        return \in_array($attribute, $this->attributesToHighlight) ||
            in_array('*', $this->attributesToHighlight) && \in_array($attribute, $this->searchableAttributes);
    }
}
