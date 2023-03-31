<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Tokenizer;

use voku\helper\UTF8;

class Token
{
    public function __construct(
        private string $token,
        private int $startPosition,
        private array $variants
    ) {
    }

    public function all(): array
    {
        return array_unique(array_merge([$this->getToken()], $this->getVariants()));
    }

    public function getLength(): int
    {
        return UTF8::strlen($this->getToken());
    }

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getVariants(): array
    {
        return $this->variants;
    }

    public function matchesToken(self $token): bool
    {
        foreach ($token->all() as $v) {
            if (\in_array($v, $this->all(), true)) {
                return true;
            }
        }

        return false;
    }
}
