<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\Span;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Cropper implements AbstractTransformer
{
    public function __construct(
        private int $cropLength,
        private string $cropMarker,
        private string $highlightStartTag,
        private string $highlightEndTag
    ) {
    }

    public function transform(string $text, TokenCollection $matches): string
    {
        if (empty($matches) || $this->cropLength <= 0) {
            return $text;
        }

        // Split the text into chunks based on the highlight tags
        $chunks = [];
        foreach (explode($this->highlightStartTag, $text) as $outer) {
            foreach (explode($this->highlightEndTag, $outer, 2) as $inner) {
                $chunks[] = $inner;
            }
        }

        if (\count($chunks) < 3 || \count($chunks) % 2 !== 1) {
            return $text;
        }

        // Create context window spans around each highlighted chunk
        $textLength = \mb_strlen($text, 'UTF-8');
        $startTagLength = \mb_strlen($this->highlightStartTag, 'UTF-8');
        $endTagLength = \mb_strlen($this->highlightEndTag, 'UTF-8');
        $position = 0;
        $spans = [];
        foreach ($chunks as $i => $chunk) {
            $chunkLength = \mb_strlen($chunk, 'UTF-8');

            if ($i % 2 === 0) {
                $position += $chunkLength;
                continue;
            }

            $highlightStart = $position;
            $highlightEnd = $position + $startTagLength + $chunkLength + $endTagLength;
            $position = $highlightEnd;

            if ($chunkLength >= $this->cropLength) {
                $spans[] = new Span($highlightStart, $highlightEnd);
                continue;
            }

            $contextPadding = (int) floor(($this->cropLength - $chunkLength) / 2);
            $contextStart = max(0, $highlightStart - $contextPadding);
            $contextEnd = min($textLength, $highlightEnd + $contextPadding);
            $adjustedContextStart = max(0, min($contextStart, $highlightEnd - $this->cropLength));
            $adjustedContextEnd = min($textLength, max($contextEnd, $highlightStart + $this->cropLength));

            $span = new Span(
                $this->closestWordBoundary($text, $adjustedContextStart, false),
                $this->closestWordBoundary($text, $adjustedContextEnd, true),
            );

            $prev = $spans[count($spans) - 1] ?? null;
            if ($prev && $prev->getEndPosition() >= $span->getStartPosition()) {
                $span = new Span($prev->getStartPosition(), max($prev->getEndPosition(), $span->getEndPosition()));
                array_pop($spans);
            }

            $spans[] = $span;
        }

        // Put back together and add crop markers
        $result = '';
        foreach ($spans as $span) {
            if ($span->getStartPosition() > 0) {
                $result .= $this->cropMarker;
            }
            $result .= mb_substr($text, $span->getStartPosition(), $span->getLength(), 'UTF-8');
            if ($span->getEndPosition() < $textLength) {
                $result .= $this->cropMarker;
            }
        }

        // Remove duplicate crop markers
        $result = str_replace($this->cropMarker . $this->cropMarker, $this->cropMarker, $result);

        return $result;
    }

    private function closestWordBoundary(string $string, int $position, bool $forward = true): int
    {
        $boundaries = [];
        foreach ([' ', "\r", "\n", "\t", ','] as $char) {
            if ($forward) {
                $boundary = mb_strpos($string, $char, $position, 'UTF-8');
                if (false !== $boundary) {
                    $boundaries[] = $boundary;
                }
            } else {
                $boundary = mb_strrpos($string, $char, 0 - (mb_strlen($string) - $position), 'UTF-8');
                if (false !== $boundary) {
                    $boundaries[] = $boundary + 1;
                }
            }
        }

        if (empty($boundaries)) {
            return $position;
        }

        return $forward ? min($boundaries) : max($boundaries);
    }
}
