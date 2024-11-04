<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Token
{
    /**
     * @param array<string> $variants
     */
    public function __construct(
        private string $term,
        private int $startPosition,
        private array $variants,
        private bool $isPartOfPhrase
    ) {
    }

    /**
     * @return array<string>
     */
    public function allTerms(): array
    {
        return array_unique(array_merge([$this->getTerm()], $this->getVariants()));
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
     * @return array<string>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    public function isPartOfPhrase(): bool
    {
        return $this->isPartOfPhrase;
    }
}
