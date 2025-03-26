<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatter;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

class Formatter
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function format(
        string $attribute,
        string $text,
        TokenCollection $terms,
        FormatterOptions $options
    ): FormatterResult {
        return new FormatterResult($this->engine, $attribute, $text, $terms, $options);
    }
}
