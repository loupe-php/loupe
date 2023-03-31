<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Highlighter;

class HighlightResult
{
    /**
     * @param array<int, array{start: int, length: int}> $matches
     */
    public function __construct(
        private string $highlightedText,
        private array $matches
    ) {
    }


    public function getHighlightedText(): string
    {
        return $this->highlightedText;
    }


    public function getMatches(): array
    {
        return $this->matches;
    }
}
