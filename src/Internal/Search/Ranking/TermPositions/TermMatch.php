<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

final class TermMatch
{
    /**
     * @param array<Position> $positions
     */
    public function __construct(
        private readonly string $attribute,
        private array $positions
    ) {
        \assert($this->positions !== []);
        sort($this->positions);
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function getFirstPosition(): Position
    {
        return $this->positions[0];
    }

    public function getLowestNumberOfTypos(): int
    {
        $lowestNumber = PHP_INT_MAX;

        foreach ($this->positions as $position) {
            if ($position->numberOfTypos < $lowestNumber) {
                $lowestNumber = $position->numberOfTypos;
            }

            // Shortcut
            if ($lowestNumber === 0) {
                return 0;
            }
        }

        return $lowestNumber;
    }

    public function getPositionAfter(int $referencePosition): ?Position
    {
        foreach ($this->positions as $position) {
            if ($position->position > $referencePosition) {
                return $position;
            }
        }

        return null;
    }
}
