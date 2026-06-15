<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

use Loupe\Matcher\Tokenizer\Token;
use Loupe\Matcher\Tokenizer\TokenCollection;
use Loupe\Matcher\Tokenizer\TokenizerInterface;

class MatcherTokenizerAdapter implements TokenizerInterface
{
    public function __construct(
        private readonly Tokenizer $tokenizer
    ) {
    }

    public function matches(Token $token, TokenCollection $tokens): bool
    {
        return $this->tokenizer->matches($token, $tokens);
    }

    public function tokenize(string $string, ?int $maxTokens = null): TokenCollection
    {
        return $this->tokenizer->tokenize($string, $maxTokens);
    }
}
