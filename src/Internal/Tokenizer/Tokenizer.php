<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Matcher\Tokenizer\Token;
use Loupe\Matcher\Tokenizer\TokenCollection;
use Loupe\Matcher\Tokenizer\Tokenizer as LoupeMatcherTokenizer;
use Loupe\Matcher\Tokenizer\TokenizerInterface;
use Nitotm\Eld\LanguageDetector;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class Tokenizer implements TokenizerInterface
{
    /**
     * @var array<string,array<string,string>>
     */
    private array $stemmerCache = [];

    /**
     * @var array<string,?Stemmer>
     */
    private array $stemmers = [];

    /**
     * @var array<string,?TokenizerInterface>
     */
    private array $tokenizers = [];

    public function __construct(
        private Engine $engine,
        private LanguageDetector $languageDetector,
    ) {
    }

    public function matches(Token $token, TokenCollection $tokens): bool
    {
        $configuration = $this->engine->getConfiguration();
        $firstCharTypoCountsDouble = $configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($tokens->all() as $queryToken) {
            foreach ($queryToken->allTerms() as $queryTerm) {
                $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($queryTerm);

                if ($levenshteinDistance === 0) {
                    if (\in_array($queryTerm, $token->allTerms(), true)) {
                        return true;
                    }
                } else {
                    foreach ($token->allTerms() as $textTerm) {
                        if (Levenshtein::damerauLevenshtein($queryTerm, $textTerm, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                            return true;
                        }
                    }
                }
            }
        }

        $lastToken = $tokens->last();

        if ($lastToken === null) {
            return false;
        }

        $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($lastToken->getTerm());

        // Prefix search (only if minimum token length is fulfilled)
        if (mb_strlen($token->getTerm()) <= $configuration->getMinTokenLengthForPrefixSearch()) {
            return false;
        }

        $chars = mb_str_split($token->getTerm(), 1, 'UTF-8');
        $prefix = implode('', \array_slice($chars, 0, $configuration->getMinTokenLengthForPrefixSearch()));
        $rest = \array_slice($chars, $configuration->getMinTokenLengthForPrefixSearch());

        if (Levenshtein::damerauLevenshtein($lastToken->getTerm(), $prefix, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
            return true;
        }

        while ($rest !== []) {
            $prefix .= array_shift($rest);

            if (Levenshtein::damerauLevenshtein($lastToken->getTerm(), $prefix, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $stopWords
     */
    public function tokenize(string $string, ?int $maxTokens = null, array $stopWords = [], bool $includeStopWords = false): TokenCollection
    {
        $language = null;
        $languageResult = $this->languageDetector->detect($string);

        // For one simple string we have to check if the language result is reliable. There's not enough data for
        // something like "Star Wars". It might be detected as nonsense, and we get weird stemming results.
        if ($languageResult->isReliable()) {
            $language = $languageResult->language;
        }

        return $this->doTokenize($string, $language, $maxTokens, $stopWords, $includeStopWords);
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
            foreach ((array) $languageResult->scores() as $lang => $score) {
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

    /**
     * @param array<string> $stopWords
     */
    private function doTokenize(string $string, ?string $language, ?int $maxTokens = null, array $stopWords = [], bool $includeStopWords = false): TokenCollection
    {
        if (!isset($this->tokenizers[$language])) {
            $this->tokenizers[$language] = new LoupeMatcherTokenizer($language);
        }

        $tokenCollection = $this->tokenizers[$language]->tokenize($string, $maxTokens, $stopWords, $includeStopWords);
        $tokenCollectionWithVariants = new TokenCollection();

        foreach ($tokenCollection->all() as $token) {
            $variants = [];

            // Stem if we detected a language - but only if not part of a phrase
            if ($language !== null && !$token->isPartOfPhrase()) {
                $stem = $this->stem($token->getTerm(), $language);
                if ($stem !== null && $token->getTerm() !== $stem) {
                    $variants = [$stem];
                }
            }

            $token = $token->withVariants($variants);

            if (!$token->isStopWord()) {
                foreach ($variants as $variant) {
                    if (\in_array($variant, $stopWords, true)) {
                        $token = $token->withMarkAsStopWord();
                    }
                }
            }

            $tokenCollectionWithVariants->add($token);
        }

        return $tokenCollectionWithVariants;
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
