<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Tokenizer;

class TokenCollection
{
    /**
     * @param Token[] $tokens
     */
    private array $tokens = [];

    public function __construct(array $tokens = [])
    {
        foreach ($tokens as $token) {
            $this->add($token);
        }
    }

    public function add(Token $token): self
    {
        $this->tokens[] = $token;

        return $this;
    }

    /**
     * @return Token[]
     */
    public function all(): array
    {
        return $this->tokens;
    }

    /**
     * @return array<string>
     */
    public function allTokens(): array
    {
        $tokens = [];

        foreach ($this->all() as $token) {
            $tokens[] = $token->getToken();
        }

        return array_unique($tokens);
    }

    /**
     * @return array<string>
     */
    public function allTokensWithVariants(): array
    {
        $tokens = [];

        foreach ($this->all() as $token) {
            $tokens = array_merge($tokens, $token->all());
        }

        return array_unique($tokens);
    }

    public function empty(): bool
    {
        return $this->tokens === [];
    }
}
