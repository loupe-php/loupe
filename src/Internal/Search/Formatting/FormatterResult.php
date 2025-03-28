<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

class FormatterResult
{
    /**
     * @param array<int, array{start: int, length: int, stopword: bool}> $matches
     */
    public function __construct(
        private string $formattedText,
        private array $matches
    ) {
    }

    public function getFormattedText(): string
    {
        return $this->formattedText;
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }
}
