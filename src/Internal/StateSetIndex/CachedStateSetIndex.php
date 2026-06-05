<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\StateSetIndex;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Internal\Cache\QueryCacheKey;
use Psr\Cache\CacheItemPoolInterface;
use Toflar\StateSetIndex\Alphabet\AlphabetInterface;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\MatchingStatesSnapshot;
use Toflar\StateSetIndex\StateSet\StateSetInterface;

final class CachedStateSetIndex implements StateSetIndexInterface
{
    /**
     * Version namespace rollover guard.
     * With a 60s TTL, even a very write-heavy index will not realistically hit this value within one TTL window.
     * It allows us to keep the version prefixes short.
     */
    private const CACHE_VERSION_MAX = 10_000;

    private int $cacheVersion = 0;

    public function __construct(
        private StateSetIndexInterface $inner,
        private TypoTolerance $typoTolerance,
        private CacheItemPoolInterface $cachePool,
    ) {
        $this->cacheVersion = $this->loadCacheVersion();
    }

    public function continueMatchingStatesSnapshot(string $string, MatchingStatesSnapshot $snapshot): MatchingStatesSnapshot
    {
        return $this->inner->continueMatchingStatesSnapshot($string, $snapshot);
    }

    public function createMatchingStatesSnapshot(string $string, int $editDistance, int $transpositionCost): MatchingStatesSnapshot
    {
        return $this->findOrBuildSnapshot($string, $editDistance, $transpositionCost, 2);
    }

    public function findMatchingStates(string $string, int $editDistance, int $transpositionCost, int $maxPrefixCharsToTrimForCacheReuse = 2): array
    {
        $cacheItem = $this->cachePool->getItem($this->buildCacheKey($string, $editDistance, $transpositionCost));
        if ($cacheItem->isHit()) {
            $value = $cacheItem->get();
            if (\is_array($value)) {
                try {
                    $snapshot = MatchingStatesSnapshot::fromArray($value);
                    return $snapshot->matchingStates();
                } catch (\InvalidArgumentException) {
                    // Ignore invalid cache payloads and treat as cache miss.
                }
            }
        }

        $snapshot = $this->findOrBuildSnapshot($string, $editDistance, $transpositionCost, $maxPrefixCharsToTrimForCacheReuse);
        $cacheItem->set($snapshot->toArray())->expiresAfter(QueryCacheKey::INTERACTIVE_TTL);
        $this->cachePool->save($cacheItem);

        return $snapshot->matchingStates();
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
        $this->bumpVersion();

        return $this->inner->index($strings);
    }

    public function removeFromIndex(array $strings): void
    {
        $this->bumpVersion();

        $this->inner->removeFromIndex($strings);
    }

    private function buildCacheKey(string $term, int $levenshteinDistance, int $transpositionCost): string
    {
        return QueryCacheKey::build('states', $this->cacheVersion, [
            $this->typoTolerance->getAlphabetSize(),
            $this->typoTolerance->getIndexLength(),
            $levenshteinDistance,
            $transpositionCost,
            $term,
        ]);
    }

    private function buildVersionKey(): string
    {
        return QueryCacheKey::build('states.version', 1, [
            $this->typoTolerance->getAlphabetSize(),
            $this->typoTolerance->getIndexLength(),
        ]);
    }

    private function bumpVersion(): void
    {
        $versionItem = $this->cachePool->getItem($this->buildVersionKey());
        $newVersion = ((int) ($versionItem->isHit() ? $versionItem->get() : 0)) + 1;

        if ($newVersion > self::CACHE_VERSION_MAX) {
            $newVersion = 1;
        }

        $versionItem->set($newVersion)->expiresAfter(QueryCacheKey::INTERACTIVE_TTL);
        $this->cachePool->save($versionItem);
        $this->cacheVersion = $newVersion;
    }

    private function findOrBuildSnapshot(string $string, int $editDistance, int $transpositionCost, int $maxPrefixCharsToTrimForCacheReuse): MatchingStatesSnapshot
    {
        if ($maxPrefixCharsToTrimForCacheReuse <= 0) {
            return $this->inner->createMatchingStatesSnapshot($string, $editDistance, $transpositionCost);
        }

        $termLength = mb_strlen($string);
        $maxTrim = min($maxPrefixCharsToTrimForCacheReuse, $termLength - 1);
        for ($trim = 1; $trim <= $maxTrim; ++$trim) {
            if ($termLength <= $trim) {
                break;
            }

            $prefix = mb_substr($string, 0, $termLength - $trim);
            $prefixItem = $this->cachePool->getItem($this->buildCacheKey($prefix, $editDistance, $transpositionCost));
            if (!$prefixItem->isHit()) {
                continue;
            }

            $prefixValue = $prefixItem->get();
            if (\is_array($prefixValue)) {
                try {
                    $snapshot = MatchingStatesSnapshot::fromArray($prefixValue);
                    return $this->inner->continueMatchingStatesSnapshot($string, $snapshot);
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $this->inner->createMatchingStatesSnapshot($string, $editDistance, $transpositionCost);
    }

    private function loadCacheVersion(): int
    {
        $versionItem = $this->cachePool->getItem($this->buildVersionKey());
        if (!$versionItem->isHit()) {
            return 0;
        }

        $value = $versionItem->get();
        if (!\is_int($value) || $value < 0 || $value > self::CACHE_VERSION_MAX) {
            return 0;
        }

        return $value;
    }
}
