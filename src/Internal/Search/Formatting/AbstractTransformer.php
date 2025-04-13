<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

interface AbstractTransformer
{
    /**
     * Transform the text according to the transformer's rules.
     */
    public function transform(string $text, TokenCollection $matches): string;
}
