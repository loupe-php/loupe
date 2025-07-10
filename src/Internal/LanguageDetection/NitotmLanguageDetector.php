<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\LanguageDetection;

use Nitotm\Eld\LanguageDetector;

class NitotmLanguageDetector implements LanguageDetectorInterface
{
    private LanguageDetector|null $languageDetector = null;

    /**
     * @param array<string> $languages
     */
    public function __construct(
        private readonly array $languages
    ) {
    }

    /**
     * @param array<string, string> $document
     */
    public function detectForDocument(array $document): DocumentResult
    {
        $bestScoresPerLanguage = [];
        $languagePerAttribute = [];
        foreach ($document as $attribute => $value) {
            $languageResult = $this->getLanguageDetector()->detect($value);

            // Store the best score per language
            foreach ($languageResult->scores() as $lang => $score) {
                if (isset($bestScoresPerLanguage[$lang])) {
                    $bestScoresPerLanguage[$lang] = max($bestScoresPerLanguage[$lang], $score);
                } else {
                    $bestScoresPerLanguage[$lang] = $score;
                }
            }

            // If the language detection was reliable, we use this language for that attribute
            if ($languageResult->isReliable()) {
                $languagePerAttribute[$attribute] = $languageResult->language;
            }
        }

        // The overall highest score is the best language for the entire document (if any)
        $bestLanguage = null;
        if ($bestScoresPerLanguage !== []) {
            /** @var string $bestLanguage */
            $bestLanguage = array_keys($bestScoresPerLanguage, max($bestScoresPerLanguage), true)[0];
        }

        return new DocumentResult($languagePerAttribute, $bestLanguage);
    }

    public function detectForString(string $string): ?string
    {
        $language = null;
        $languageResult = $this->getLanguageDetector()->detect($string);

        // For one simple string we have to check if the language result is reliable. There's not enough data for
        // something like "Star Wars". It might be detected as nonsense, and we get weird stemming results.
        if ($languageResult->isReliable()) {
            $language = $languageResult->language;
        }

        return $language;
    }

    /**
     * Lazy initialize the LanguageDetector because it does all sorts of stuff in the constructor.
     * That way we can prevent loading useless stuff into memory if e.g. you only call $engine->countDocuments()
     * or anything else that does not need language detection.
     */
    private function getLanguageDetector(): LanguageDetector
    {
        if ($this->languageDetector === null) {
            $this->languageDetector = new LanguageDetector();
            $this->languageDetector->enableTextCleanup(true); // Clean stuff like URLs, domains etc. to improve language detection
            $this->languageDetector->langSubset($this->languages); // Use the subset (unfortunately this is still loading the "small" ngrams set as well, see https://github.com/nitotm/efficient-language-detector/issues/15)
        }

        return $this->languageDetector;
    }
}
