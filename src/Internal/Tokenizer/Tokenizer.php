<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

use Nitotm\Eld\LanguageDetector;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class Tokenizer
{
    /**
     * @var array<string,array<string,string>>
     */
    private array $stemmerCache = [];

    /**
     * @var array<string,?Stemmer>
     */
    private array $stemmers = [];

    public function __construct(
        private LanguageDetector $languageDetector
    ) {
    }

    public function tokenize(string $string, ?int $maxTokens = null): TokenCollection
    {
        $language = null;
        $languageResult = $this->languageDetector->detect($string);

        // For one simple string we have to check if the language result is reliable. There's not enough data for
        // something like "Star Wars". It might be detected as nonsense, and we get weird stemming results.
        if ($languageResult->isReliable()) {
            $language = $languageResult->language;
        }

        return $this->doTokenize($string, $language, $maxTokens);
    }

    /**
     * @param array<string, string> $document
     * @return array<string, TokenCollection>
     */
    public function tokenizeDocument(array $document): array
    {
        $bestScoresPerLanguage = [];
        $languagePerAttribute = [];
        foreach ($document as $attribute => $value) {
            $languageResult = $this->languageDetector->detect($value);

            // Store the best score per language
            foreach ((array) $languageResult->scores as $lang => $score) {
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

        $result = [];

        foreach ($document as $attribute => $value) {
            // Tokenize using the language that was either detected or the best for the entire document
            $result[$attribute] = $this->doTokenize($value, $languagePerAttribute[$attribute] ?? $bestLanguage);
        }

        return $result;
    }

    private function doTokenize(string $string, ?string $language, ?int $maxTokens = null): TokenCollection
    {
        $iterator = \IntlRuleBasedBreakIterator::createWordInstance($language); // @phpstan-ignore-line - null is allowed
        $iterator->setText($string);

        $collection = new TokenCollection();
        $id = 0;
        $position = 0;
        $phrase = false;
        $negated = false;
        $whitespace = true;

        foreach ($iterator->getPartsIterator() as $term) {
            // Set negation if the previous token was not a word and we're not in a phrase
            if (!$phrase && $whitespace) {
                $negated = false;
                if ($term === '-') {
                    $negated = true;
                }
            }

            // Toggle phrases between quotes
            if ($term === '"') {
                $phrase = !$phrase;
                if (!$phrase) {
                    $negated = false;
                }
            }

            $status = $iterator->getRuleStatus();
            $word = $this->isWord($status);
            $whitespace = $this->isWhitespace($status, $term);

            if (!$word) {
                $position += mb_strlen($term, 'UTF-8');
                continue;
            }

            if ($maxTokens !== null && $collection->count() >= $maxTokens) {
                break;
            }

            $term = mb_strtolower($term, 'UTF-8');
            $variants = [];

            // Stem if we detected a language - but only if not part of a phrase
            if ($language !== null && !$phrase) {
                $stem = $this->stem($term, $language);
                if ($stem !== null && $term !== $stem) {
                    $variants = [$stem];
                }
            }

            $token = new Token(
                $id++,
                $term,
                $position,
                $variants,
                $phrase,
                $negated
            );

            $collection->add($token);
            $position += $token->getLength();
        }

        return $collection;
    }

    private function getStemmerForLanguage(string $language): ?Stemmer
    {
        if (isset($this->stemmers[$language])) {
            return $this->stemmers[$language];
        }

        try {
            $stemmer = StemmerFactory::create($language);
        } catch (NotFoundException) {
            $stemmer = null;
        }

        return $this->stemmers[$language] = $stemmer;
    }

    private function isWhitespace(?int $status, string $token): bool
    {
        return ($status === null || ($status >= \IntlBreakIterator::WORD_NONE && $status < \IntlBreakIterator::WORD_NONE_LIMIT)) && mb_trim($token) === '';
    }

    private function isWord(?int $status): bool
    {
        return $status >= \IntlBreakIterator::WORD_NONE_LIMIT;
    }

    private function stem(string $term, string $language): ?string
    {
        if (isset($this->stemmerCache[$language][$term])) {
            return $this->stemmerCache[$language][$term];
        }

        $stemmer = $this->getStemmerForLanguage($language);

        if ($stemmer === null) {
            return null;
        }

        return $this->stemmerCache[$language][$term] = mb_strtolower($stemmer->stem($term), 'UTF-8');
    }
}
