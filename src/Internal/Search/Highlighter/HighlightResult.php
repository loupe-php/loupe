<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Highlighter;

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

    /**
     * @return array<int, array{start: int, length: int}>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }
}
