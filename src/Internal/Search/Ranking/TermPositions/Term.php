<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

class Term
{
    /**
     * @param array<TermMatch> $termMatches
     */
    public function __construct(
        private readonly array $termMatches,
        private readonly bool $hasExactMatch = false,
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
            if (0 === $lowestNumber) {
                return 0;
            }
        }

        return $lowestNumber;
    }

    /**
     * @return array<TermMatch>
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
