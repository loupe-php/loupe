<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatter;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Formatter
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function format(
        string $text,
        TokenCollection $queryTokens,
        string $startTag = '<em>',
        string $endTag = '</em>',
    ): FormatterResult {
        if ($text === '') {
            return new FormatterResult($text, []);
        }

        $matches = [];
        $stopWords = $this->engine->getConfiguration()->getStopWords();
        $textTokens = $this->engine->getTokenizer()->tokenize($text);

        foreach ($textTokens->all() as $textToken) {
            if ($this->matches($textToken, $queryTokens)) {
                $matches[] = [
                    'start' => $textToken->getStartPosition(),
                    'length' => $textToken->getLength(),
                    'stopword' => $textToken->isOneOf($stopWords),
                ];
            }
        }

        if ($matches === []) {
            return new FormatterResult($text, []);
        }

        // Sort matches by start
        uasort($matches, function (array $a, array $b) {
            return $a['start'] <=> $b['start'];
        });

        $pos = 0;
        $highlightedText = '';
        $spans = $this->extractSpansFromMatches($matches);

        foreach (mb_str_split($text, 1, 'UTF-8') as $pos => $char) {
            if (\in_array($pos, $spans['starts'], true)) {
                $highlightedText .= $startTag;
            }
            if (\in_array($pos, $spans['ends'], true)) {
                $highlightedText .= $endTag;
            }

            $highlightedText .= $char;
        }

        // Match at the end of the $text
        if (\in_array($pos + 1, $spans['ends'], true)) {
            $highlightedText .= $endTag;
        }

        return new FormatterResult($highlightedText, $matches);
    }

    /**
     * @param array<array{start:int, length:int, stopword:bool}> $matches
     * @return array{starts: array<int>, ends: array<int>}
     */
    private function extractSpansFromMatches(array $matches): array
    {
        $spans = [
            'starts' => [],
            'ends' => [],
        ];
        $lastEnd = null;

        $matches = $this->removeStopWordMatches($matches);

        foreach ($matches as $match) {
            $end = $match['start'] + $match['length'];

            // Merge matches that are exactly after one another
            if ($lastEnd === $match['start'] - 1) {
                $highestEnd = max($spans['ends']);
                unset($spans['ends'][array_search($highestEnd, $spans['ends'], true)]);
            } else {
                $spans['starts'][] = $match['start'];
            }

            $spans['ends'][] = $end;
            $lastEnd = $end;
        }

        return $spans;
    }

    private function matches(Token $textToken, TokenCollection $queryTokens): bool
    {
        $configuration = $this->engine->getConfiguration();
        $firstCharTypoCountsDouble = $configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($queryTokens->all() as $queryToken) {
            foreach ($queryToken->allTerms() as $term) {
                $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($term);

                if ($levenshteinDistance === 0) {
                    if (\in_array($term, $textToken->allTerms(), true)) {
                        return true;
                    }
                } else {
                    foreach ($textToken->allTerms() as $textTerm) {
                        if (Levenshtein::damerauLevenshtein($term, $textTerm, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
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
