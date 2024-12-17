<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

class Term
{
    /**
     * @param array<TermMatch> $termMatches
     */
    public function __construct(
        private array $termMatches
    ) {
    }

    /**
     * @return TermMatch[]
     */
    public function getMatches(): array
    {
        return $this->termMatches;
    }

    public function hasMatches(): bool
    {
        return !empty($this->termMatches);
    }
}
