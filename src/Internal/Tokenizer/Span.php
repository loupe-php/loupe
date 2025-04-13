<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Span
{
    public function __construct(
        private int $startPosition,
        private int $endPosition,
    ) {}

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getEndPosition(): int
    {
        return $this->endPosition;
    }

    public function getLength(): int
    {
        return $this->endPosition - $this->startPosition;
    }

    public function withEndPosition(int $endPosition): Span
    {
        return new Span($this->startPosition, $endPosition);
    }
}
