<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatter;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Tokenizer\Token;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class FormatterResult
{
    private ?string $formattedText = null;

    /**
     * @var array<int, array{start: int, length: int, stopword: bool}>
     */
    private ?array $matches = null;

    public function __construct(
        private Engine $engine,
        private string $attribute,
        private string $text,
        private TokenCollection $queryTokens,
        private FormatterOptions $options
    ) {
    }

    public function getFormattedText(): ?string
    {
        $this->formattedText ??= $this->formatText();

        return $this->formattedText;
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    public function getMatches(): array
    {
        $this->matches ??= $this->calculateMatches();

        return $this->matches;
    }

    /**
     * @return array<int, array{start: int, length: int, stopword: bool}>
     */
    private function calculateMatches(): array
    {
        if ($this->text === '') {
            return [];
        }

        $matches = [];
        $stopWords = $this->engine->getConfiguration()->getStopWords();
        $textTokens = $this->engine->getTokenizer()->tokenize($this->text);

        foreach ($textTokens->all() as $textToken) {
            if ($this->queryMatchesToken($textToken)) {
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

    private function crop(string $text): string
    {
        // $matches = $this->getMatches();
        // $cropLength = $this->options->getCropLength();
        // $cropMarker = $this->options->getCropMarker();

        return $text;
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

    private function formatText(): string
    {
        $matches = $this->getMatches();

        if (empty($matches)) {
            return $this->text;
        }

        $formattedText = $this->text;

        if ($this->options->shouldHighlightAttribute($this->attribute)) {
            $formattedText = $this->highlight($formattedText);
        }

        if ($this->options->shouldCropAttribute($this->attribute)) {
            $formattedText = $this->crop($formattedText);
        }

        return $formattedText;
    }

    private function highlight(string $text): string
    {
        $matches = $this->getMatches();
        $startTag = $this->options->getHighlightStartTag();
        $endTag = $this->options->getHighlightEndTag();

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

        return $highlightedText;
    }

    private function queryMatchesToken(Token $textToken): bool
    {
        $configuration = $this->engine->getConfiguration();
        $firstCharTypoCountsDouble = $configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($this->queryTokens->all() as $queryToken) {
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

        $lastToken = $this->queryTokens->last();

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
