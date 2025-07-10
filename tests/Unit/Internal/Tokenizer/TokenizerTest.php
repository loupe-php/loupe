<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Tokenizer;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\LanguageDetection\NitotmLanguageDetector;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Matcher\StopWords\InMemoryStopWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testMaximumTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.', 5);

        $this->assertSame(5, $tokens->count());

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
        ], $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.', 5)
            ->allTermsWithVariants());
    }

    public function testNegatedPhrases(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, -mein -"Name ist Hase" und -ich "weiß von" -nichts.');

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'ist',
            'hase',
            'und',
            'ich',
            'weiß',
            'von',
            'nichts',
            'nicht',
        ], $tokens->allTermsWithVariants());

        $this->assertSame([
            'mein',
            'name',
            'ist',
            'hase',
            'ich',
            'nichts',
            'nicht',
        ], $tokens->allNegatedTermsWithVariants());
    }

    public function testNegatedTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein -Name ist -Hase und ich weiß von -nichts.');

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'und',
            'ich',
            'weiß',
            'weiss',
            'von',
            'nichts',
            'nicht',
        ], $tokens->allTermsWithVariants());

        $this->assertSame([
            'name',
            'nam',
            'hase',
            'has',
            'nichts',
            'nicht',
        ], $tokens->allNegatedTermsWithVariants());
    }

    public function testNegatedWordPartPhraseTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('-Hallo, mein -Name-ist-Hase und -"ich weiß" von 64-bit-Dingen.');

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'und',
            'ich',
            'weiß',
            'von',
            '64',
            'bit',
            'dingen',
            'ding',
        ], $tokens->allTermsWithVariants());

        $this->assertSame([
            'hallo',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'ich',
            'weiß',
        ], $tokens->allNegatedTermsWithVariants());
    }

    public function testNegatedWordPartTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein -Name-ist-Hase und -ich weiß von 64-bit-Dingen.');

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'und',
            'ich',
            'weiß',
            'weiss',
            'von',
            '64',
            'bit',
            'dingen',
            'ding',
        ], $tokens->allTermsWithVariants());

        $this->assertSame([
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'ich',
        ], $tokens->allNegatedTermsWithVariants());
    }

    public function testStopWords(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize(
            'Hallo, mein Name ist Hase und ich weiß von nichts.',
        )->withoutStopwords(new InMemoryStopWords(['ist', 'und', 'von']), true);

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'hase',
            'has',
            'ich',
            'weiß',
            'weiss',
            'nichts',
            'nicht',
        ], $tokens->allTermsWithVariants());
    }

    public function testStopWordsOnly(): void
    {
        $tokenizer = $this->createTokenizer();

        $tokensWithStopWords = $tokenizer->tokenize(
            'ist nicht seltsam',
        )->withoutStopwords(new InMemoryStopWords(['ist', 'nicht']), true);

        $this->assertSame(['seltsam'], $tokensWithStopWords->allTermsWithVariants());

        $tokensWithStopWordsOnly = $tokenizer->tokenize(
            'ist oder nicht',
        )->withoutStopwords(new InMemoryStopWords(['ist', 'oder', 'nicht']), true);

        $this->assertSame(['ist', 'oder', 'nicht'], $tokensWithStopWordsOnly->allTermsWithVariants());
    }

    /**
     * @param array<string> $languages
     * @param array<string> $expectedTokens
     */
    #[DataProvider('tokenizationWithLanguageSubsetProvider')]
    public function testTokenizationWithLanguageSubset(string $string, array $languages, array $expectedTokens): void
    {
        $tokenizer = $this->createTokenizer(Configuration::create()->withLanguages($languages));
        $this->assertSame($expectedTokens, $tokenizer->tokenize($string)
            ->allTermsWithVariants());
    }

    public function testTokenize(): void
    {
        $tokenizer = $this->createTokenizer();
        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
            'und',
            'ich',
            'weiß',
            'weiss',
            'von',
            'nichts',
            'nicht',
        ], $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.')
            ->allTermsWithVariants());
    }

    public function testTokenizeWithPhrases(): void
    {
        $tokenizer = $this->createTokenizer();
        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'ist',
            'hase',
            'und',
            'ich',
            'weiß',
            'weiss',
            'von',
            'nichts',
            'nicht',
        ], $tokenizer->tokenize('Hallo, mein "Name ist Hase" und ich weiß von nichts.')
            ->allTermsWithVariants());
    }

    public static function tokenizationWithLanguageSubsetProvider(): \Generator
    {
        yield 'Test German extracts as expected on German text' => [
            'Hallo, mein Name ist Hase und ich weiß von nichts.',
            ['de'],
            [
                'hallo',
                'mein',
                'name',
                'nam',
                'ist',
                'hase',
                'has',
                'und',
                'ich',
                'weiß',
                'weiss',
                'von',
                'nichts',
                'nicht',
            ],
        ];

        yield 'Test English extracts as expected on German text' => [
            'Hallo, mein Name ist Hase und ich weiß von nichts.',
            ['en'],
            [
                'hallo',
                'mein',
                'name',
                'ist',
                'hase',
                'und',
                'ich',
                'weiß',
                'von',
                'nichts',
            ],
        ];
    }

    private function createTokenizer(Configuration|null $configuration = null): Tokenizer
    {
        $configuration = $configuration ?? Configuration::create();
        $languageDetector = new NitotmLanguageDetector($configuration->getLanguages());

        return new Tokenizer($this->createMock(Engine::class), $languageDetector);
    }
}
