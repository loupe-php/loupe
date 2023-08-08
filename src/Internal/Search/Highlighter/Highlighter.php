<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Highlighter;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use voku\helper\UTF8;

class Highlighter
{
    public function __construct(
        private Configuration $configuration,
        private Tokenizer $tokenizer
    ) {
    }

    public function highlight(string $text, TokenCollection $queryTokens): HighlightResult
    {
        if ($text === '') {
            return new HighlightResult($text, []);
        }

        $matches = [];

        foreach ($this->tokenizer->tokenize($text)->all() as $textToken) {
            if ($this->matches($textToken, $queryTokens)) {
                $matches[] = [
                    'start' => $textToken->getStartPosition(),
                    'length' => $textToken->getLength(),
                ];
            }
        }

        if ($matches === []) {
            return new HighlightResult($text, []);
        }

        // Sort matches by start
        uasort($matches, function (array $a, array $b) {
            return $a['start'] <=> $b['start'];
        });

        $startTag = '<em>';
        $endTag = '</em>';

        $highlightedText = '';
        $spans = $this->extractSpansFromMatches($matches);

        foreach (UTF8::str_split($text) as $pos => $char) {
            if (\in_array($pos, $spans['starts'], true)) {
                $highlightedText .= $startTag;
            }
            if (\in_array($pos, $spans['ends'], true)) {
                $highlightedText .= $endTag;
            }

            $highlightedText .= $char;
        }

        return new HighlightResult($highlightedText, $matches);
    }

    /**
     * @param array<array{start:int, length:int}> $matches
     * @return array{starts: array<int>, ends: array<int>}
     */
    private function extractSpansFromMatches(array $matches): array
    {
        $spans = [
            'starts' => [],
            'ends' => [],
        ];
        $lastEnd = 0;

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
        $firstCharTypoCountsDouble = $this->configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($queryTokens->all() as $queryToken) {
            foreach ($queryToken->allTerms() as $term) {
                $levenshteinDistance = $this->configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($term);

                if ($levenshteinDistance === 0) {
                    if (\in_array($term, $textToken->allTerms(), true)) {
                        return true;
                    }
                } else {
                    foreach ($textToken->allTerms() as $textTerm) {
                        if (Levenshtein::levenshtein($term, $textTerm, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
