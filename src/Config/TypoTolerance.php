<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Config;

use Terminal42\Loupe\Exception\InvalidConfigurationException;
use voku\helper\UTF8;

final class TypoTolerance
{
    private int $alphabetSize = 4;

    private bool $firstCharTypoCountsDouble = true;

    private int $indexLength = 16;

    private bool $isDisabled = false;

    private array $termThresholds = [
        9 => 2,
        5 => 1,
    ];

    public static function create(): self
    {
        return new self();
    }

    public function disable(): self
    {
        $clone = clone $this;
        $clone->isDisabled = true;
        $clone->termThresholds = [];

        return $clone;
    }

    public function firstCharTypoCountsDouble(): bool
    {
        return $this->firstCharTypoCountsDouble;
    }

    public function getAlphabetSize(): int
    {
        return $this->alphabetSize;
    }

    public function getIndexLength(): int
    {
        return $this->indexLength;
    }

    public function getLevenshteinDistanceForTerm(string $term): int
    {
        if ($this->isDisabled()) {
            return 0;
        }

        $termLength = (int) UTF8::strlen($term);

        foreach ($this->termThresholds as $threshold => $distance) {
            if ($termLength >= $threshold) {
                return $distance;
            }
        }

        return 0;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }

    public function withAlphabetSize(int $alhabetSize): self
    {
        $clone = clone $this;
        $clone->alphabetSize = $alhabetSize;

        return $clone;
    }

    public function withFirstCharTypoCountsDouble(bool $firstCharTypoCountsDouble): self
    {
        $clone = clone $this;
        $clone->firstCharTypoCountsDouble = $firstCharTypoCountsDouble;

        return $clone;
    }

    public function withIndexLength(int $indexLength): self
    {
        $clone = clone $this;
        $clone->indexLength = $indexLength;

        return $clone;
    }

    public function withTermThresholds(array $termThresholds): self
    {
        krsort($termThresholds);

        foreach ($termThresholds as $threshold => $distance) {
            if (! is_int($threshold) || ! is_int($distance)) {
                throw new InvalidConfigurationException('Invalid threshold configuration format.');
            }
        }

        $clone = clone $this;
        $clone->termThresholds = $termThresholds;

        return $clone;
    }
}
