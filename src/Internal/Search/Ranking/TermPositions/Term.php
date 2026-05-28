<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

class Term
{
    /**
     * @param array<TermMatch> $termMatches
     */
    public function __construct(
        private array $termMatches,
        private bool $hasExactMatch = false
    ) {
    }

    public function getLowestNumberOfTypos(): int
    {
        $lowestNumber = PHP_INT_MAX;

        foreach ($this->termMatches as $termMatch) {
            $termLowestNumber = $termMatch->getLowestNumberOfTypos();
            if ($termLowestNumber < $lowestNumber) {
                $lowestNumber = $termLowestNumber;
            }

            // Shortcut
            if ($lowestNumber === 0) {
                return 0;
            }
        }

        return $lowestNumber;
    }

    /**
     * @return TermMatch[]
     */
    public function getMatches(): array
    {
        return $this->termMatches;
    }

    public function hasExactMatch(): bool
    {
        return $this->hasExactMatch;
    }

    public function hasMatches(): bool
    {
        return !empty($this->termMatches);
    }
}
