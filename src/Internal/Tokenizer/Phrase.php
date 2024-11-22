<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Phrase
{
    /**
     * @param array<Token> $tokens
     */
    public function __construct(
        private array $tokens,
        private bool $isNegated
    ) {
    }

    public function addToken(Token $token): self
    {
        $this->tokens[] = $token;

        return $this;
    }

    /**
     * @return array<Token>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function isNegated(): bool
    {
        return $this->isNegated;
    }
}
