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

    public function getPositionAfter(int $referencePosition): ?Position
    {
        foreach ($this->positions as $position) {
            if ($position->position > $referencePosition) {
                return $position;
            }
        }

        return null;
    }

    public function hasExactMatch(): bool
    {
        foreach ($this->positions as $position) {
            if ($position->isExactMatch) {
                return true;
            }
        }

        return false;
    }
}
