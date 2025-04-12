<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Phrase extends TokenCollection
{
    /**
     * @param array<Token> $tokens
     */
    public function __construct(
        private array $tokens,
        private bool $isNegated
    ) {
        parent::__construct($tokens);
    }

    public function isNegated(): bool
    {
        return $this->isNegated;
    }
}
