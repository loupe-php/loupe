<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Cropper implements AbstractTransformer
{
    public function __construct(
        private Matcher $matcher,
        private int $cropLength,
        private string $cropMarker
    ) {
    }

    public function transform(string $text, TokenCollection $matches): string
    {
        if (empty($matches) || $this->cropLength <= 0) {
            return $text;
        }

        return $text;
    }
}
