<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\LanguageDetection\LanguageDetectorInterface;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Matcher\Locale;
use Loupe\Matcher\Tokenizer\Normalizer\Normalizer;
use Loupe\Matcher\Tokenizer\Token;
use Loupe\Matcher\Tokenizer\TokenCollection;
use Loupe\Matcher\Tokenizer\Tokenizer as LoupeMatcherTokenizer;
use Loupe\Matcher\Tokenizer\TokenizerInterface;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class Tokenizer implements TokenizerInterface
{
    /**
     * @var array<string, list<string>>|null
     */
    private array|null $synonymLookup = null;

    /**
     * @var array<string, LoupeMatcherTokenizer>
     */
    private array $languageTokenizers = [];

    private readonly LoupeMatcherTokenizer $noLanguageTokenizer;

    /**
     * @var array<string, array<string, string>>
     */
    private array $stemmerCache = [];

    /**
     * @var array<string, ?Stemmer>
     */
    private array $stemmers = [];

    public function __construct(
        private readonly Engine $engine,
        private readonly LanguageDetectorInterface $languageDetector,
    ) {
        $this->noLanguageTokenizer = new LoupeMatcherTokenizer();
    }

    public function matches(Token $token, TokenCollection $tokens): bool
    {
        $configuration = $this->engine->getConfiguration();
        $firstCharTypoCountsDouble = $configuration->getTypoTolerance()->firstCharTypoCountsDouble();

        foreach ($tokens->all() as $queryToken) {
            foreach ($queryToken->allTerms() as $queryTerm) {
                $levenshteinDistance = $configuration->getTypoTolerance()->getLevenshteinDistanceForTerm($queryTerm);

                if (0 === $levenshteinDistance) {
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

        if (null === $lastToken) {
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

        while ([] !== $rest) {
            $prefix .= array_shift($rest);

            if (Levenshtein::damerauLevenshtein($lastToken->getTerm(), $prefix, $firstCharTypoCountsDouble) <= $levenshteinDistance) {
                return true;
            }
        }

        return false;
    }

    public function tokenize(string $string, bool $withVariants = true, int|null $maxTokens = null): TokenCollection
    {
        $language = $this->languageDetector->detectForString($string);

        if (!$withVariants) {
            return $this->tokenizeWithoutVariants($string, $language, $maxTokens);
        }

        return $this->tokenizeWithVariants($string, $language, $maxTokens);
    }

    /**
     * @param array<string, string> $document
     *
     * @return array<string, TokenCollection>
     */
    public function tokenizeDocument(array $document): array
    {
        $languageDetectionResult = $this->languageDetector->detectForDocument($document);

        $result = [];

        foreach ($document as $attribute => $value) {
            // Tokenize using the language that was either detected for the attribute or the best for the entire document
            $result[$attribute] = $this->tokenizeWithVariants($value, $languageDetectionResult->getBestLanguageForAttribute($attribute) ?? $languageDetectionResult->getBestLanguageForDocument());
        }

        return $result;
    }

    public function tokenizeQuery(string $query, int|null $maxTokens = null): TokenCollection
    {
        $language = $this->languageDetector->detectForQuery($query);

        return $this->tokenizeWithoutVariants($query, $language, $maxTokens);
    }

    private function getLanguageTokenizer(string|null $language): LoupeMatcherTokenizer
    {
        if (null === $language) {
            return $this->noLanguageTokenizer;
        }

        if (!isset($this->languageTokenizers[$language])) {
            $locale = Locale::fromString($language);
            $this->languageTokenizers[$language] = LoupeMatcherTokenizer::createFromPreconfiguredLocaleConfiguration($locale);
        }

        return $this->languageTokenizers[$language];
    }

    private function tokenizeWithoutVariants(string $string, string|null $language, int|null $maxTokens = null): TokenCollection
    {
        return $this->getLanguageTokenizer($language)->tokenize($string, false, $maxTokens);
    }

    private function tokenizeWithVariants(string $string, string|null $language, int|null $maxTokens = null): TokenCollection
    {
        $tokenCollection = $this->getLanguageTokenizer($language)->tokenize($string, true, $maxTokens);
        $tokenCollectionWithVariants = new TokenCollection();
        $synonymLookup = $this->getSynonymLookup();

        foreach ($tokenCollection->all() as $token) {
            $variants = [];

            // Stem if we detected a language - but only if not part of a phrase
            if (
                null !== $language
                && !$token->isPartOfPhrase()
                && !$this->engine->getConfiguration()->getTypoTolerance()->isDisabled()
            ) {
                $stem = $this->stem($token->getTerm(), $language);
                if (null !== $stem && $token->getTerm() !== $stem) {
                    $variants = [$stem];
                }
            }

            $token = $token->withAddedVariants($variants);
            if ([] !== $synonymLookup) {
                $synonymVariants = [];

                foreach ($token->allTerms() as $term) {
                    foreach ($synonymLookup[$term] ?? [] as $searchTerm) {
                        $synonymVariants[] = $searchTerm;
                    }
                }
                $token = $token->withAddedVariants($synonymVariants);
            }

            $tokenCollectionWithVariants->add($token);
        }

        return $tokenCollectionWithVariants;
    }

    private function getStemmerForLanguage(string $language): Stemmer|null
    {
        if (\array_key_exists($language, $this->stemmers)) {
            return $this->stemmers[$language];
        }

        try {
            $stemmer = StemmerFactory::create($language);
        } catch (NotFoundException) {
            $stemmer = null;
        }

        return $this->stemmers[$language] = $stemmer;
    }

    private function stem(string $term, string $language): string|null
    {
        if (isset($this->stemmerCache[$language][$term])) {
            return $this->stemmerCache[$language][$term];
        }

        $stemmer = $this->getStemmerForLanguage($language);

        if (null === $stemmer) {
            return null;
        }

        return $this->stemmerCache[$language][$term] = mb_strtolower($stemmer->stem($term), 'UTF-8');
    }

    /**
     * @return array<string, list<string>>
     */
    private function getSynonymLookup(): array
    {
        if (null !== $this->synonymLookup) {
            return $this->synonymLookup;
        }

        $normalizer = new Normalizer();
        $lookup = [];

        foreach ($this->engine->getConfiguration()->getSynonyms() as $searchTerm => $documentTerms) {
            $normalizedSearchTerm = $normalizer->normalize($searchTerm);

            foreach ($documentTerms as $documentTerm) {
                $lookup[$normalizer->normalize($documentTerm)][] = $normalizedSearchTerm;
            }
        }

        foreach ($lookup as $documentTerm => $searchTerms) {
            $lookup[$documentTerm] = array_values(array_unique($searchTerms));
        }

        return $this->synonymLookup = $lookup;
    }
}
