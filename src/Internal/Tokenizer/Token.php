<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

class Token
{
    private int $length;

    /**
     * @param array<string> $variants
     */
    public function __construct(
        private int $id,
        private string $term,
        private int $startPosition,
        private array $variants,
        private bool $isPartOfPhrase,
        private bool $isNegated,
        private bool $isStopWord,
    ) {
        $this->length = mb_strlen($this->term, 'UTF-8');
    }

    /**
     * @return array<string>
     */
    public function allTerms(): array
    {
        return array_unique(array_merge([$this->getTerm()], $this->getVariants()));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getEndPosition(): int
    {
        return $this->startPosition + $this->length;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    /**
     * Return an array with a single element, the token itself.
     * Useful for iterating over a TokenCollection with tokens and phrases.
     *
     * @return array<Token>
     */
    public function all(): array
    {
        return [$this];
    }

    /**
     * @return array<string>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    public function isNegated(): bool
    {
        return $this->isNegated;
    }

    /**
     * @param array<string> $haystack
     */
    public function isOneOf(array $haystack): bool
    {
        if ($haystack === []) {
            return false;
        }

        foreach ($this->allTerms() as $term) {
            foreach ($haystack as $needle) {
                if ($term === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isPartOfPhrase(): bool
    {
        return $this->isPartOfPhrase;
    }

    public function isStopWord(): bool
    {
        return $this->isStopWord;
    }
}
