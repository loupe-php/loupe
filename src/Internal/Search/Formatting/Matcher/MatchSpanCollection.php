<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting\Matcher;

class MatchSpanCollection
{
    /**
     * @param array<MatchSpan> $spans
     */
    public function __construct(
        private array $spans
    ) {}

    public function all(): array
    {
        return $this->spans;
    }
}
