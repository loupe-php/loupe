<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Toflar\StateSetIndex\Alphabet\AlphabetInterface;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\MatchingStatesSnapshot;
use Toflar\StateSetIndex\StateSet\StateSetInterface;

interface StateSetIndexInterface
{
    public function continueMatchingStatesSnapshot(string $string, MatchingStatesSnapshot $snapshot): MatchingStatesSnapshot;

    public function createMatchingStatesSnapshot(string $string, int $editDistance, int $transpositionCost): MatchingStatesSnapshot;

    /**
     * @return array<int>
     */
    public function findMatchingStates(string $string, int $editDistance, int $transpositionCost, int $maxPrefixCharsToTrimForCacheReuse = 0): array;

    public function getAlphabet(): AlphabetInterface;

    public function getConfig(): Config;

    public function getStateSet(): StateSetInterface;

    /**
     * @param array<string> $strings
     * @return array<string, int>
     */
    public function index(array $strings): array;

    /**
     * @param array<string> $strings
     */
    public function removeFromIndex(array $strings): void;
}
