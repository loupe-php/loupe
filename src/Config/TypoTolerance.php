<?php

declare(strict_types=1);

namespace Loupe\Loupe\Config;

use Loupe\Loupe\Exception\InvalidConfigurationException;

final class TypoTolerance
{
    private int $alphabetSize = 4;

    private bool $firstCharTypoCountsDouble = true;

    private int $indexLength = 14;

    private bool $isDisabled = false;

    private bool $isEnabledForPrefixSearch = false;

    /**
     * @var array<int, int>
     */
    private array $typoThresholds = [
        9 => 2,
        5 => 1,
    ];

    private bool $useOPcache = false;

    public static function create(): self
    {
        return new self();
    }

    public function disable(): self
    {
        $clone = clone $this;
        $clone->isDisabled = true;
        $clone->typoThresholds = [];
        $clone->isEnabledForPrefixSearch = false;

        return $clone;
    }

    public static function disabled(): self
    {
        return (new self())->disable();
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

        $termLength = (int) mb_strlen($term, 'UTF-8');

        foreach ($this->typoThresholds as $threshold => $distance) {
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

    public function isEnabledForPrefixSearch(): bool
    {
        return $this->isEnabledForPrefixSearch;
    }

    public function useOPcache(): bool
    {
        return $this->useOPcache;
    }

    public function withAlphabetSize(int $alhabetSize): self
    {
        $clone = clone $this;
        $clone->alphabetSize = $alhabetSize;

        return $clone;
    }

    public function withEnabledForPrefixSearch(bool $enable): self
    {
        $clone = clone $this;
        $clone->isEnabledForPrefixSearch = $enable;

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

    /**
     * @param array<int, int> $typoThresholds
     */
    public function withTypoThresholds(array $typoThresholds): self
    {
        krsort($typoThresholds);

        foreach ($typoThresholds as $threshold => $distance) {
            if (!\is_int($threshold) || !\is_int($distance)) {
                throw new InvalidConfigurationException('Invalid threshold configuration format.');
            }
        }

        $clone = clone $this;
        $clone->typoThresholds = $typoThresholds;

        return $clone;
    }

    public function withUseOPcache(bool $useOpcache): self
    {
        $clone = clone $this;
        $clone->useOPcache = $useOpcache;
        return $clone;
    }
}
