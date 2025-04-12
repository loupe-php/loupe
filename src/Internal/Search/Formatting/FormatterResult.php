<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class FormatterResult
{
    public function __construct(
        private string $formattedText,
        private TokenCollection $matches
    ) {
    }

    public function getFormattedText(): string
    {
        return $this->formattedText;
    }

    public function getMatches(): TokenCollection
    {
        return $this->matches;
    }

    public function hasMatches(): bool
    {
        return $this->matches->count() > 0;
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    public function getMatchesArray(): array
    {
        return array_map(fn (Token $token) => [
            'start' => $token->getStartPosition(),
            'length' => $token->getLength(),
            'stopword' => $token->isStopWord(),
        ], $this->matches->all());
    }
}
