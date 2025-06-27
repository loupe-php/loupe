<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\LanguageDetection;

class PreselectedLanguageDetector implements LanguageDetectorInterface
{
    public function __construct(
        private readonly string $language
    ) {
        \assert($this->language !== '');
    }

    public function detectForDocument(array $document): DocumentResult
    {
        return new DocumentResult([], $this->language);
    }

    public function detectForString(string $string): ?string
    {
        return $this->language;
    }
}
