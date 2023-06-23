<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Highlighter;

use Terminal42\Loupe\Configuration;
use Terminal42\Loupe\Internal\Tokenizer\Token;
use Terminal42\Loupe\Internal\Tokenizer\TokenCollection;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;
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
            if (in_array($pos, $spans['starts'], true)) {
                $highlightedText .= $startTag;
            }
            if (in_array($pos, $spans['ends'], true)) {
                $highlightedText .= $endTag;
            }

            $highlightedText .= $char;
        }

        return new HighlightResult($highlightedText, $matches);
    }

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

    private function matches(Token $textToken, TokenCollection $queryTokens)
    {
        foreach ($queryTokens->all() as $queryToken) {
            foreach ($queryToken->all() as $term) {
                $levenshteinDistance = $this->configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($term);

                if ($levenshteinDistance === 0) {
                    if (\in_array($term, $textToken->all(), true)) {
                        return true;
                    }
                } else {
                    foreach ($textToken->all() as $textTerm) {
                        if (levenshtein($term, $textTerm) <= $levenshteinDistance) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
