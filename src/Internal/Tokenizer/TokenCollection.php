<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class TokenCollection implements \Countable
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

    public function remove(Token $token): self
    {
        $this->tokens = array_filter($this->tokens, fn (Token $t) => $t !== $token);

        return $this;
    }

    /**
     * @return Token[]
     */
    public function all(): array
    {
        return $this->tokens;
    }

    public function atIndex(int $index): ?Token
    {
        return $this->tokens[$index] ?? null;
    }

    public function atPosition(int $index): ?Token
    {
        foreach ($this->tokens as $token) {
            if ($token->getStartPosition() <= $index && $index <= $token->getEndPosition()) {
                return $token;
            }
        }

        return null;
    }

    public function indexOf(Token $token): ?int
    {
        foreach ($this->tokens as $index => $t) {
            if ($t === $token) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return Token[]
     */
    public function allNegated(): array
    {
        return array_filter($this->tokens, fn (Token $token) => $token->isNegated());
    }

    /**
     * @return array<string>
     */
    public function allNegatedTerms(): array
    {
        $tokens = [];

        foreach ($this->allNegated() as $token) {
            $tokens[] = $token->getTerm();
        }

        return array_unique($tokens);
    }

    /**
     * @return array<string>
     */
    public function allNegatedTermsWithVariants(): array
    {
        $tokens = [];

        foreach ($this->allNegated() as $token) {
            $tokens = array_merge($tokens, $token->allTerms());
        }

        return array_unique($tokens);
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

    /**
     * Return an array of "phrase groups" -- either single tokens or phrases as single objects.
     *
     * @return array<Phrase|Token>
     */
    public function phraseGroups(): array
    {
        $groups = [];
        $phrase = null;

        foreach ($this->tokens as $token) {
            if ($token->isPartOfPhrase()) {
                $phrase = $phrase ?? new Phrase([], $token->isNegated());
                $phrase->add($token);
            } else {
                if ($phrase) {
                    $groups[] = $phrase;
                    $phrase = null;
                }
                $groups[] = $token;
            }
        }

        if ($phrase) {
            $groups[] = $phrase;
        }

        return $groups;
    }

    public function first(): ?Token
    {
        $first = reset($this->tokens);
        if ($first instanceof Token) {
            return $first;
        }

        return null;
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
