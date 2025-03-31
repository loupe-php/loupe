<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\SearchParameters;

class FormatterOptions
{
    /**
     * @var array<string>
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
        array $searchableAttributes
    ) {
        $this->attributesToCrop = ['*'] === $this->searchParameters->getAttributesToCrop()
            ? $searchableAttributes
            : $this->searchParameters->getAttributesToCrop();

        $this->attributesToHighlight = ['*'] === $this->searchParameters->getAttributesToHighlight()
            ? $searchableAttributes
            : $this->searchParameters->getAttributesToHighlight();
    }

    public function getCropLength(): int
    {
        return $this->searchParameters->getCropLength();
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
        return \in_array($attribute, $this->attributesToCrop, true);
    }

    public function shouldHighlightAttribute(string $attribute): bool
    {
        return \in_array($attribute, $this->attributesToHighlight, true);
    }
}
