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
     * @return Token[]
     */
    public function allStopWords(): array
    {
        return array_filter($this->tokens, fn (Token $token) => $token->isStopWord());
    }

    /**
     * @return array<string>
     */
    public function allStopWordTerms(): array
    {
        $tokens = [];

        foreach ($this->allStopWords() as $token) {
            $tokens[] = $token->getTerm();
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
     * Return an array of "token groups" -- either single tokens or phrases as single objects.
     *
     * @return array<Phrase|Token>
     */
    public function getGroups(): array
    {
        $groups = [];
        $phrase = null;

        foreach ($this->tokens as $token) {
            if ($token->isPartOfPhrase()) {
                if (!$phrase) {
                    $phrase = new Phrase([$token], $token->isNegated());
                } else {
                    $phrase->addToken($token);
                }
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

    public function last(): ?Token
    {
        $last = end($this->tokens);
        if ($last instanceof Token) {
            return $last;
        }

        return null;
    }
}
