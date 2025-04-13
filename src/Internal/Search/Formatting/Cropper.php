<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\TokenCollection;
use Symfony\Component\String\UnicodeString;

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

        $context = new UnicodeString($text);
        $chunks = [];

        foreach ($context->split($this->highlightStartTag) as $chunk) {
            foreach ($chunk->split($this->highlightEndTag, 2) as $innerChunk) {
                $chunks[] = $innerChunk;
            }
        }

        if (\count($chunks) < 3 || \count($chunks) % 2 !== 1) {
            return $text;
        }

        $result = [];

        foreach ($chunks as $i => $chunk) {
            // Odd = highlighted key phrases, leave untouched and surround with tags
            if ($i % 2 === 1) {
                $result[] = $chunk->prepend($this->highlightStartTag)->append($this->highlightEndTag)->toString();
                continue;
            }

            // Even = context window around highlighted phrases

            if ($i === 0) {
                // The first chunk only ever has to be prepended
                $result[] = $this->trim($chunk, true)->toString();
            } elseif ($i === \count($chunks) - 1) {
                // The last chunk only ever has to be appended
                $result[] = $this->trim($chunk, false)->toString();
            } elseif ($chunk->length() <= $this->cropLength) {
                // An in-between chunk has to be left untouched, if it is shorter or equal the desired context length
                $result[] = $chunk->toString();
            } else {
                // Otherwise we have to prepend and append
                $pre = $this->trim($chunk, true);
                $post = $this->trim($chunk, false);

                // If both have been shortened, we would have a double ellipsis now, so let's trim that
                if ($post->endsWith($this->cropMarker) && $pre->startsWith($this->cropMarker)) {
                    $post = $post->trimSuffix($this->cropMarker);
                }

                $result[] = $post->append($pre->toString())->toString();
            }
        }

        return implode('', $result);
    }

    private function trim(UnicodeString $string, bool $fromEnd): UnicodeString
    {
        $truncated = $fromEnd
            ? $string->reverse()->truncate($this->cropLength, cut: false)->reverse()
            : $string->truncate($this->cropLength, cut: false);

        if ($truncated->equalsTo($string)) {
            return $string;
        }

        return $fromEnd
            ? $truncated->prepend($this->cropMarker)
            : $truncated->append($this->cropMarker);
    }
}
