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

    /**
     * @return array<string>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function addToken(Token $token): self
    {
        $this->tokens[] = $token;

        return $this;
    }

    public function isNegated(): bool
    {
        return $this->isNegated;
    }
}
