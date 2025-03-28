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
     * Highlight the matches in the text.
     *
     * @param array{starts: array<int>, ends: array<int>} $spans
     * @return array{0: string, 1: array{starts: array<int>, ends: array<int>}}
     */
    public function transform(string $text, array $spans): array
    {
        if (empty($spans)) {
            return [$text, $spans];
        }

        $startTag = $this->options->getHighlightStartTag();
        $endTag = $this->options->getHighlightEndTag();

        $result = '';
        $pos = 0;

        foreach (mb_str_split($text, 1, 'UTF-8') as $pos => $char) {
            if (\in_array($pos, $spans['starts'], true)) {
                $result .= $startTag;
            }
            if (\in_array($pos, $spans['ends'], true)) {
                $result .= $endTag;
            }

            $result .= $char;
        }

        // Match at the end of the $text
        if (\in_array($pos + 1, $spans['ends'], true)) {
            $result .= $endTag;
        }

        return [$result, $spans];
    }
}
