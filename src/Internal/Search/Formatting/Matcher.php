<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Matcher
{
    public function __construct(
        private Engine $engine
    ) {
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    public function calculateMatches(string $text, TokenCollection $queryTokens): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        $stopWords = $this->engine->getConfiguration()->getStopWords();
        $textTokens = $this->engine->getTokenizer()->tokenize($text);

        foreach ($textTokens->all() as $textToken) {
            if ($this->isMatch($textToken, $queryTokens)) {
                $matches[] = [
                    'start' => $textToken->getStartPosition(),
                    'length' => $textToken->getLength(),
                    'stopword' => $textToken->isOneOf($stopWords),
                ];
            }
        }

        // Sort matches by start
        uasort($matches, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $matches;
    }

    /**
     * @param array<array{start:int, length:int, stopword:bool}> $matches
     * @return array<array{start:int, end:int}>
     */
    public function mergeMatchesIntoSpans(array $matches): array
    {
        $matches = $this->removeStopWordMatches($matches);

        $spans = [];
        $previousEnd = null;

        foreach ($matches as $match) {
            $end = $match['start'] + $match['length'];

            // Merge matches that are exactly after one another
            if ($previousEnd === $match['start'] - 1) {
                $spans[count($spans) - 1]['end'] = $end;
            } else {
                $spans[] = [
                    'start' => $match['start'],
                    'end' => $end
                ];
            }

            $previousEnd = $end;
        }

        return $spans;
    }

    private function isMatch(Token $textToken, TokenCollection $queryTokens): bool
    {
        $configuration = $this->engine->getConfiguration();
        $firstCharTypoCountsDouble = $configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($queryTokens->all() as $queryToken) {
            foreach ($queryToken->allTerms() as $queryTerm) {
                $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($queryTerm);

                if ($levenshteinDistance === 0) {
                    if (\in_array($queryTerm, $textToken->allTerms(), true)) {
                        return true;
                    }
                } else {
                    foreach ($textToken->allTerms() as $textTerm) {
                        if (Levenshtein::damerauLevenshtein($queryTerm, $textTerm, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                            return true;
                        }
                    }
                }
            }
        }

        $lastToken = $queryTokens->last();

        if ($lastToken === null) {
            return false;
        }

        $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($lastToken->getTerm());

        // Prefix search (only if minimum token length is fulfilled)
        if (mb_strlen($textToken->getTerm()) <= $configuration->getMinTokenLengthForPrefixSearch()) {
            return false;
        }

        $chars = mb_str_split($textToken->getTerm(), 1, 'UTF-8');
        $prefix = implode('', \array_slice($chars, 0, $configuration->getMinTokenLengthForPrefixSearch()));
        $rest = \array_slice($chars, $configuration->getMinTokenLengthForPrefixSearch());

        if (Levenshtein::damerauLevenshtein($lastToken->getTerm(), $prefix, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
            return true;
        }

        while ($rest !== []) {
            $prefix .= array_shift($rest);

            if (Levenshtein::damerauLevenshtein($lastToken->getTerm(), $prefix, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array{start:int, length:int, stopword:bool}> $matches
     * @return array<array{start:int, length:int, stopword:bool}> $matches
     */
    private function removeStopWordMatches(array $matches): array
    {
        $maxCharDistance = 1;
        $maxWordDistance = 1;

        foreach ($matches as $i => $match) {
            if (!$match['stopword']) {
                continue;
            }

            $hasNonStopWordNeighbor = false;

            for ($j = 1; $j <= $maxWordDistance; $j++) {
                $prevMatch = $matches[$i - $j] ?? null;
                $nextMatch = $matches[$i + $j] ?? null;

                // Keep stopword matches between non-stopword matches of interest
                $hasNonStopWordNeighbor = ($prevMatch && $prevMatch['stopword'] === false && ($prevMatch['start'] + $prevMatch['length']) >= $match['start'] - $maxCharDistance)
                    || ($nextMatch && $nextMatch['stopword'] === false && $nextMatch['start'] <= $match['start'] + $match['length'] + $maxCharDistance);

                if ($hasNonStopWordNeighbor) {
                    break;
                }
            }

            if (!$hasNonStopWordNeighbor) {
                unset($matches[$i]);
            }
        }

        return $matches;
    }
}
