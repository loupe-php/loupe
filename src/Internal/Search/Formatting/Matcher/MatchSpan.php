<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting\Matcher;

class MatchSpan
{
    public function __construct(
        private int $startPosition,
        private int $length,
        private bool $isHighlighted
    ) {}

    public function isHighlighted(): bool
    {
        return $this->isHighlighted;
    }

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getEndPosition(): int
    {
        return $this->startPosition + $this->length;
    }
}
