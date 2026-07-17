<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\LanguageDetection;

class DocumentResult
{
    /**
     * @param array<string, string|null> $bestLanguagePerAttribute
     */
    public function __construct(
        private readonly array $bestLanguagePerAttribute,
        private readonly string|null $bestLanguageForDocument,
    ) {
    }

    public function getBestLanguageForAttribute(string $attribute): string|null
    {
        return $this->bestLanguagePerAttribute[$attribute] ?? null;
    }

    public function getBestLanguageForDocument(): string|null
    {
        return $this->bestLanguageForDocument;
    }
}
