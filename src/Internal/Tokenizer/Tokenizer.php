<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Tokenizer;

use LanguageDetection\Language;
use voku\helper\UTF8;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

class Tokenizer
{
    public const MAX_NGRAMS = 9000;

    private Language $language;

    /**
     * @var array<string,array<string,string>>
     */
    private array $stemmerCache = [];

    /**
     * @var array<string,?Stemmer>
     */
    private array $stemmers = [];

    public function __construct()
    {
        $this->language = new Language([], self::getNgramsDir());
        $this->language->setMaxNgrams(9000);
    }

    public static function getNgramsDir(): string
    {
        return __DIR__ . '/../../../Resources/language-ngrams';
    }

    public function tokenize(string $string, ?int $maxTokens = null): TokenCollection
    {
        $language = $this->language->detect($string)
            ->limit(0, 3);

        return $this->doTokenize($string, (string) $language, $maxTokens);
    }

    private function doTokenize(string $string, string $language, ?int $maxTokens = null): TokenCollection
    {
        $iterator = \IntlRuleBasedBreakIterator::createWordInstance($language);
        $iterator->setText($string);

        $collection = new TokenCollection();
        $position = 0;
        $phrase = false;

        foreach ($iterator->getPartsIterator() as $term) {
            if ($term === '"') {
                $position++;
                $phrase = !$phrase;
                continue;
            }

            if ($iterator->getRuleStatus() === \IntlBreakIterator::WORD_NONE) {
                $position += UTF8::strlen($term);
                continue;
            }

            if ($maxTokens !== null && $collection->count() >= $maxTokens) {
                break;
            }

            $variants = [];

            // Only stem if not part of a phrase
            if (!$phrase) {
                $stem = $this->stem($term, $language);
                if ($stem !== null) {
                    $variants = [$stem];
                }
            }

            $token = new Token(
                UTF8::strtolower($term),
                $position,
                $variants,
                $phrase
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

    private function stem(string $term, string $language): ?string
    {
        if (isset($this->stemmerCache[$language][$term])) {
            return $this->stemmerCache[$language][$term];
        }

        $stemmer = $this->getStemmerForLanguage($language);

        if ($stemmer === null) {
            return null;
        }

        return $this->stemmerCache[$language][$term] = UTF8::strtolower($stemmer->stem($term));
    }
}
