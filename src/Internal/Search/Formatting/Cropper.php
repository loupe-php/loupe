<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

class Cropper implements AbstractTransformer
{
    public function __construct(
        private FormatterOptions $options
    ) {
    }

    /**
     * Transform the text and matches according to the transformer's rules.
     * Each transformer may modify both the text and the matched spans.
     * The spans should be adjusted if the text is modified in a way that affects match positions.
     *
     * @param array<array{start:int, end:int}> $spans
     * @return array{0: string, 1: array<array{start:int, end:int}>}
     */
    public function transform(string $text, array $spans): array
    {
        if (empty($spans)) {
            return [$text, $spans];
        }

        $cropLength = $this->options->getCropLength();
        $cropMarker = $this->options->getCropMarker();

        if ($cropLength <= 0) {
            return [$text, $spans];
        }

        $wordPositions = $this->getWordPositions($text);
        $totalWords = count($wordPositions);

        $result = '';
        $adjustedSpans = [];
        $offset = 0;

        foreach ($spans as $spanIndex => $span) {
            // Find which word contains the span start
            $startWordIndex = $this->findWordIndex($wordPositions, $span['start']);
            $endWordIndex = $this->findWordIndex($wordPositions, $span['end'] - 1);

            // Calculate crop window
            $windowStart = max(0, $startWordIndex - $cropLength);
            $windowEnd = min($totalWords - 1, $endWordIndex + $cropLength);

            // Add crop marker at the beginning if needed
            if ($windowStart > 0) {
                $result .= $cropMarker;
                $offset = mb_strlen($cropMarker, 'UTF-8');
            }

            // Calculate text positions for the crop window
            $textStart = $windowStart > 0 ? $wordPositions[$windowStart]['start'] : 0;
            $textEnd = $windowEnd < $totalWords - 1 ?
                $wordPositions[$windowEnd]['end'] :
                mb_strlen($text, 'UTF-8');

            // Extract the cropped text
            $croppedText = mb_substr($text, $textStart, $textEnd - $textStart, 'UTF-8');
            $result .= $croppedText;

            // Adjust span positions
            $adjustedSpan = [
                'start' => $span['start'] - $textStart + $offset,
                'end' => $span['end'] - $textStart + $offset
            ];
            $adjustedSpans[] = $adjustedSpan;

            // Add crop marker at the end if needed
            if ($windowEnd < $totalWords - 1) {
                $result .= $cropMarker;
            }

            // If there are more spans, add a separator
            if ($spanIndex < count($spans) - 1) {
                $result .= $cropMarker;
                $offset = mb_strlen($result, 'UTF-8');
            }
        }

        return [$result, $adjustedSpans];
    }

    /**
     * Get the start and end positions of each word in the text
     *
     * @return array<int, array{start: int, end: int}>
     */
    private function getWordPositions(string $text): array
    {
        $positions = [];
        $pattern = '/\S+/u';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $word = $match[0];
                $start = $match[1];
                $end = $start + mb_strlen($word, 'UTF-8');

                $positions[] = [
                    'start' => $start,
                    'end' => $end
                ];
            }
        }

        return $positions;
    }

    /**
     * Find the index of the word that contains the given position
     */
    private function findWordIndex(array $wordPositions, int $position): int
    {
        foreach ($wordPositions as $index => $wordPosition) {
            if ($position >= $wordPosition['start'] && $position < $wordPosition['end']) {
                return $index;
            }
        }

        // If position is after the last word, return the last word index
        if (!empty($wordPositions) && $position >= end($wordPositions)['end']) {
            return count($wordPositions) - 1;
        }

        return 0;
    }
}
