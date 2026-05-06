<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Toflar\StateSetIndex\Alphabet\AlphabetInterface;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\MatchingStatesSnapshot;
use Toflar\StateSetIndex\StateSet\StateSetInterface;
use Toflar\StateSetIndex\StateSetIndex;

final class DefaultStateSetIndex implements StateSetIndexInterface
{
    public function __construct(
        private StateSetIndex $inner,
    ) {
    }

    public function continueMatchingStatesSnapshot(string $string, MatchingStatesSnapshot $snapshot): MatchingStatesSnapshot
    {
        return $this->inner->continueMatchingStatesSnapshot($string, $snapshot);
    }

    public function createMatchingStatesSnapshot(string $string, int $editDistance, int $transpositionCost): MatchingStatesSnapshot
    {
        return $this->inner->createMatchingStatesSnapshot($string, $editDistance, $transpositionCost);
    }

    public function findMatchingStates(string $string, int $editDistance, int $transpositionCost, int $maxPrefixCharsToTrimForCacheReuse = 2): array
    {
        return $this->inner->findMatchingStates($string, $editDistance, $transpositionCost);
    }

    public function getAlphabet(): AlphabetInterface
    {
        return $this->inner->getAlphabet();
    }

    public function getConfig(): Config
    {
        return $this->inner->getConfig();
    }

    public function getStateSet(): StateSetInterface
    {
        return $this->inner->getStateSet();
    }

    public function index(array $strings): array
    {
        return $this->inner->index($strings);
    }

    public function removeFromIndex(array $strings): void
    {
        $this->inner->removeFromIndex($strings);
    }
}
