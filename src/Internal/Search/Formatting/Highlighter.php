<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

class Highlighter implements AbstractTransformer
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

        $startTag = $this->options->getHighlightStartTag();
        $endTag = $this->options->getHighlightEndTag();

        $result = '';
        $previousEnd = 0;

        foreach ($spans as $span) {
            // Insert start tag before span
            $result .= mb_substr($text, $previousEnd, $span['start'] - $previousEnd, 'UTF-8');
            $result .= $startTag;
            // Insert span text
            $result .= mb_substr($text, $span['start'], $span['end'] - $span['start'], 'UTF-8');
            // Insert end tag after span
            $result .= $endTag;
            $previousEnd = $span['end'];
        }

        // Add remaining text after last span
        $result .= mb_substr($text, $previousEnd, null, 'UTF-8');

        return [$result, $spans];
    }
}
