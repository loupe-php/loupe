<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Highlighter;

use Terminal42\Loupe\Internal\Tokenizer\TokenCollection;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;

class Highlighter
{
    public function __construct(
        private Tokenizer $tokenizer
    ) {
    }

    public function highlight(string $text, TokenCollection $tokens): HighlightResult
    {
        if ($text === '') {
            return new HighlightResult($text, []);
        }

        $matches = [];

        foreach ($this->tokenizer->tokenize($text)->all() as $textToken) {
            foreach ($tokens->all() as $token) {
                if ($textToken->matchesToken($token)) {
                    $matches[] = [
                        'start' => $textToken->getStartPosition(),
                        'length' => $textToken->getLength(),
                    ];
                }
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

        foreach (mb_str_split($text) as $pos => $char) {
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
}
