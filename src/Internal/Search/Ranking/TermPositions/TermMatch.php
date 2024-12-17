<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking\TermPositions;

final class TermMatch
{
    /**
     * @param array<int> $positions
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

    public function getFirstPosition(): int
    {
        return $this->positions[0];
    }

    public function getPositionAfter(int $referencePosition): ?int
    {
        foreach ($this->positions as $position) {
            if ($position > $referencePosition) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    public function getPositions(): array
    {
        return $this->positions;
    }
}
