<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Tokenizer\Span;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Matcher
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function calculateMatches(string $text, TokenCollection $queryTokens): TokenCollection
    {
        if ($text === '') {
            return new TokenCollection();
        }

        $matches = [];
        $stopWords = $this->engine->getConfiguration()->getStopWords();
        $textTokens = $this->engine->getTokenizer()->tokenize($text, stopWords: $stopWords, includeStopWords: true);

        $matches = new TokenCollection();
        foreach ($textTokens->all() as $textToken) {
            if ($this->isMatch($textToken, $queryTokens)) {
                $matches->add($textToken);
            }
        }

        return $matches;
    }

    /**
     * @return Span[]
     */
    public function calculateMatchSpans(TokenCollection $matches): array
    {
        $matches = $this->removeSolitaryStopWords($matches);

        $spans = [];
        $prevMatch = null;

        foreach ($matches->all() as $match) {
            // Merge matches that are exactly after one another
            $prevSpan = end($spans);
            if ($prevSpan && $prevMatch && $prevMatch->getEndPosition() === $match->getStartPosition() - 1) {
                array_splice($spans, -1, 1, [$prevSpan->withEndPosition($match->getEndPosition())]);
            } else {
                $spans[] = new Span($match->getStartPosition(), $match->getEndPosition());
            }

            $prevMatch = $match;
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

    private function removeSolitaryStopWords(TokenCollection $matches): TokenCollection
    {
        $maxCharDistance = 1;
        $maxWordDistance = 1;

        $result = new TokenCollection();

        foreach ($matches->all() as $i => $match) {
            if (!$match->isStopWord()) {
                $result->add($match);
                continue;
            }

            $hasNonStopWordNeighbor = false;

            for ($j = 1; $j <= $maxWordDistance; $j++) {
                $prevMatch = $matches->atIndex($i - $j);
                $nextMatch = $matches->atIndex($i + $j);

                // Keep stopword matches between non-stopword matches of interest
                $hasNonStopWordNeighbor = ($prevMatch && !$prevMatch->isStopWord() && $prevMatch->getEndPosition() >= $match->getStartPosition() - $maxCharDistance)
                    || ($nextMatch && !$nextMatch->isStopWord() && $nextMatch->getStartPosition() <= $match->getEndPosition() + $maxCharDistance);

                if ($hasNonStopWordNeighbor) {
                    break;
                }
            }

            if ($hasNonStopWordNeighbor) {
                $result->add($match);
            }
        }

        return $result;
    }
}
