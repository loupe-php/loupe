<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatter;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\SearchParameters;

class FormatterOptions
{
    private array $attributesToHighlight;
    private array $attributesToCrop;

    public function __construct(
        private Engine $engine,
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

    public function requiresFormatting(): bool {
        return count($this->getAttributesToCrop()) > 0 || count($this->getAttributesToHighlight()) > 0;
    }

    public function shouldCropAttribute(string $attribute): bool {
        return in_array($attribute, $this->getAttributesToCrop(), true);
    }

    public function shouldHighlightAttribute(string $attribute): bool {
        return in_array($attribute, $this->getAttributesToHighlight(), true);
    }

    public function getAttributesToCrop(): array {
        return $this->attributesToCrop;
    }

    public function getAttributesToHighlight(): array {
        return $this->attributesToHighlight;
    }

    public function getHighlightStartTag(): string {
        return $this->searchParameters->getHighlightStartTag();
    }

    public function getHighlightEndTag(): string {
        return $this->searchParameters->getHighlightEndTag();
    }

    public function getCropLength(): int {
        return $this->searchParameters->getCropLength();
    }

    public function getCropMarker(): string {
        return $this->searchParameters->getCropMarker();
    }
}
