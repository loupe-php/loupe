<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Highlighter implements AbstractTransformer
{
    public function __construct(
        private Matcher $matcher,
        private string $startTag,
        private string $endTag
    ) {
    }

    public function transform(string $text, TokenCollection $matches): string
    {
        $spans = $this->matcher->calculateMatchSpans($matches);

        if (empty($spans)) {
            return $text;
        }

        $result = '';
        $previousEnd = 0;

        foreach ($spans as $span) {
            // Insert start tag before span
            $result .= mb_substr($text, $previousEnd, $span->getStartPosition() - $previousEnd, 'UTF-8');
            $result .= $this->startTag;

            // Insert span text
            $result .= mb_substr($text, $span->getStartPosition(), $span->getLength(), 'UTF-8');

            // Insert end tag after span
            $result .= $this->endTag;
            $previousEnd = $span->getEndPosition();
        }

        // Add remaining text after last span
        $result .= mb_substr($text, $previousEnd, null, 'UTF-8');

        return $result;
    }
}
