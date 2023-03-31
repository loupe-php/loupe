<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Tokenizer;

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

    public function tokenize(string $string): TokenCollection
    {
        $language = $this->language->detect($string)
            ->limit(0, 3);

        return $this->doTokenize($string, (string) $language);
    }

    private function doTokenize(string $string, string $language): TokenCollection
    {
        $iterator = \IntlRuleBasedBreakIterator::createWordInstance($language);
        $iterator->setText($string);

        $collection = new TokenCollection();
        $position = 0;

        foreach ($iterator->getPartsIterator() as $term) {
            if ($iterator->getRuleStatus() === \IntlBreakIterator::WORD_NONE) {
                $position += UTF8::strlen($term);
                continue;
            }

            $token = new Token(
                UTF8::strtolower($term),
                $position,
                [UTF8::strtolower($this->stem($term, $language))]
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

    private function stem(string $term, string $language): string
    {
        $stemmer = $this->getStemmerForLanguage($language);

        if ($stemmer === null) {
            return $term;
        }

        return $stemmer->stem($term);
    }
}
