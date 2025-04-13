<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Unhighlighter implements AbstractTransformer
{
    public function __construct(
        private string $startTag,
        private string $endTag
    ) {
    }

    public function transform(string $text, TokenCollection $matches): string
    {
        return str_replace([$this->startTag, $this->endTag], ['', ''], $text);
    }
}
