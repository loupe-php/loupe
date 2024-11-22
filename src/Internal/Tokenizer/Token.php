<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Token
{
    /**
     * @param array<string> $variants
     */
    public function __construct(
        private int $id,
        private string $term,
        private int $startPosition,
        private array $variants,
        private bool $isPartOfPhrase,
        private bool $isNegated
    ) {
    }

    /**
     * @return array<string>
     */
    public function allTerms(): array
    {
        return array_unique(array_merge([$this->getTerm()], $this->getVariants()));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLength(): int
    {
        return (int) mb_strlen($this->getTerm(), 'UTF-8');
    }

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    /**
     * Return an array with a single element, the token itself.
     * Useful for iterating over a TokenCollection with tokens and phrases.
     *
     * @return array<Token>
     */
    public function getTokens(): array
    {
        return [$this];
    }

    /**
     * @return array<string>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    public function isNegated(): bool
    {
        return $this->isNegated;
    }

    public function isPartOfPhrase(): bool
    {
        return $this->isPartOfPhrase;
    }
}
