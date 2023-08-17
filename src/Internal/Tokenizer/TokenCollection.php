<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class TokenCollection
{
    /**
     * @var Token[]
     */
    private array $tokens = [];

    /**
     * @param Token[] $tokens
     */
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
    public function allTerms(): array
    {
        $tokens = [];

        foreach ($this->all() as $token) {
            $tokens[] = $token->getTerm();
        }

        return array_unique($tokens);
    }

    /**
     * @return array<string>
     */
    public function allTermsWithVariants(): array
    {
        $tokens = [];

        foreach ($this->all() as $token) {
            $tokens = array_merge($tokens, $token->allTerms());
        }

        return array_unique($tokens);
    }

    public function count(): int
    {
        return \count($this->tokens);
    }

    public function empty(): bool
    {
        return $this->tokens === [];
    }

    public function last(): ?Token
    {
        $last = end($this->tokens);
        if ($last instanceof Token) {
            return $last;
        }

        return null;
    }
}
