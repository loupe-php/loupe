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

    /**
     * @param array{
     *     alphabetSize?: int,
     *     firstCharTypoCountsDouble?: bool,
     *     indexLength?: int,
     *     isDisabled?: bool,
     *     isEnabledForPrefixSearch?: bool,
     *     typoThresholds?: array<int, int>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();

        if (isset($data['isDisabled']) && $data['isDisabled'] === true) {
            $instance = $instance->disable();
        }

        if (isset($data['alphabetSize'])) {
            $instance = $instance->withAlphabetSize((int) $data['alphabetSize']);
        }

        if (isset($data['firstCharTypoCountsDouble'])) {
            $instance = $instance->withFirstCharTypoCountsDouble((bool) $data['firstCharTypoCountsDouble']);
        }

        if (isset($data['indexLength'])) {
            $instance = $instance->withIndexLength((int) $data['indexLength']);
        }

        if (isset($data['isEnabledForPrefixSearch'])) {
            $instance = $instance->withEnabledForPrefixSearch((bool) $data['isEnabledForPrefixSearch']);
        }

        if (isset($data['typoThresholds']) && \is_array($data['typoThresholds'])) {
            $instance = $instance->withTypoThresholds($data['typoThresholds']);
        }

        return $instance;
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

    /**
     * @return array{
     *     alphabetSize: int,
     *     firstCharTypoCountsDouble: bool,
     *     indexLength: int,
     *     isDisabled: bool,
     *     isEnabledForPrefixSearch: bool,
     *     typoThresholds: array<int, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'alphabetSize' => $this->alphabetSize,
            'firstCharTypoCountsDouble' => $this->firstCharTypoCountsDouble,
            'indexLength' => $this->indexLength,
            'isDisabled' => $this->isDisabled,
            'isEnabledForPrefixSearch' => $this->isEnabledForPrefixSearch,
            'typoThresholds' => $this->typoThresholds,
        ];
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
}
