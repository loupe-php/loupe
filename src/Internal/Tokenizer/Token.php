<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Tokenizer;

use voku\helper\UTF8;

class Token
{
    public function __construct(
        private string $term,
        private int $startPosition,
        private array $variants,
        private bool $isPartOfPhrase
    ) {
    }

    public function allTerms(): array
    {
        return array_unique(array_merge([$this->getTerm()], $this->getVariants()));
    }

    public function getLength(): int
    {
        return UTF8::strlen($this->getTerm());
    }

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getVariants(): array
    {
        return $this->variants;
    }

    public function isPartOfPhrase(): bool
    {
        return $this->isPartOfPhrase;
    }
}
