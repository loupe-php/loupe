<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

use Loupe\Loupe\Internal\Search\Formatting\Matcher\Matcher;
use Loupe\Loupe\Internal\Search\Formatting\Matcher\MatchSpanCollection;
use Loupe\Loupe\Internal\Tokenizer\TokenCollection;

interface AbstractTransformer
{
    public function __construct(private Matcher $matcher);

    /**
     * Transform the text according to the transformer's rules.
     */
    public function transform(string $text, TokenCollection $matches, MatchSpanCollection $spans): string;
}
