<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\LanguageDetection;

interface LanguageDetectorInterface
{
    /**
     * @param array<string, string> $document
     */
    public function detectForDocument(array $document): DocumentResult;

    public function detectForString(string $string): ?string;
}
