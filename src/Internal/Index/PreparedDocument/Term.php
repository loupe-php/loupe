<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\PreparedDocument;

class Term
{
    private int $termLength;

    public function __construct(
        private string $term,
        private string $attribute,
        private int $position,
        private bool $isVariant
    ) {
        $this->termLength = mb_strlen($term, 'UTF-8');
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getTermLength(): int
    {
        return $this->termLength;
    }

    public function isVariant(): bool
    {
        return $this->isVariant;
    }
}
