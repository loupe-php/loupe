<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Tokenizer;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\LanguageDetection\NitotmLanguageDetector;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Matcher\StopWords\InMemoryStopWords;
use Loupe\Matcher\Tokenizer\Token;
use Loupe\Matcher\Tokenizer\TokenCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase
{
    public function testMaximumTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.', true, 5);

        $this->assertCount(5, $tokens);

        $this->assertSame(
            [
                'hallo',
                'mein',
                'name',
                'nam',
                'ist',
                'hase',
                'has',
            ],
            $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.', true, 5)
                ->allTermsWithVariants(),
        );
    }

    public function testNegatedPhrases(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, -mein -"Name ist Hase" und -ich "weiß von" -nichts.');

        $this->assertSame(
            [
                'hallo',
                'mein',
                'name',
                'ist',
                'hase',
                'und',
                'ich',
                'weiss',
                'von',
                'nichts',
                'nicht',
            ],
            $tokens->allTermsWithVariants(),
        );

        $this->assertSame(
            [
                'mein',
                'name',
                'ist',
                'hase',
                'ich',
                'nichts',
                'nicht',
            ],
            $tokens->allNegatedTermsWithVariants(),
        );
    }

    public function testNegatedTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein -Name ist -Hase und ich weiß von -nichts.');

        $this->assertSame(
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
                'weiss',
                'von',
                'nichts',
                'nicht',
            ],
            $tokens->allTermsWithVariants(),
        );

        $this->assertSame(
            [
                'name',
                'nam',
                'hase',
                'has',
                'nichts',
                'nicht',
            ],
            $tokens->allNegatedTermsWithVariants(),
        );
    }

    public function testNegatedWordPartPhraseTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('-Hallo, mein -Name-ist-Hase und -"ich weiß" von 64-bit-Dingen.');

        $this->assertSame(
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
                'weiss',
                'von',
                '64',
                'bit',
                'dingen',
                'ding',
            ],
            $tokens->allTermsWithVariants(),
        );

        $this->assertSame(
            [
                'hallo',
                'name',
                'nam',
                'ist',
                'hase',
                'has',
                'ich',
                'weiss',
            ],
            $tokens->allNegatedTermsWithVariants(),
        );
    }

    public function testNegatedWordPartTokens(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize('Hallo, mein -Name-ist-Hase und -ich weiß von 64-bit-Dingen.');

        $this->assertSame(
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
                'weiss',
                'von',
                '64',
                'bit',
                'dingen',
                'ding',
            ],
            $tokens->allTermsWithVariants(),
        );

        $this->assertSame(
            [
                'name',
                'nam',
                'ist',
                'hase',
                'has',
                'ich',
            ],
            $tokens->allNegatedTermsWithVariants(),
        );
    }

    public function testStopWords(): void
    {
        $tokenizer = $this->createTokenizer();
        $tokens = $tokenizer->tokenize(
            'Hallo, mein Name ist Hase und ich weiß von nichts.',
        )->withoutStopwords(new InMemoryStopWords(['ist', 'und', 'von']), true);

        $this->assertSame(
            [
                'hallo',
                'mein',
                'name',
                'nam',
                'hase',
                'has',
                'ich',
                'weiss',
                'nichts',
                'nicht',
            ],
            $tokens->allTermsWithVariants(),
        );
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

    public function testSynonymsAreNormalized(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withSynonyms([
                'TV' => ['Télévision'],
            ]),
        );

        $token = $this->findToken($tokenizer->tokenize('télévision'), 'television');

        $this->assertContains('tv', $token->getVariants());
    }

    public function testSynonymsAreNotAppliedToQueries(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withSynonyms([
                'tv' => ['television'],
            ]),
        );

        $this->assertSame(['television'], $tokenizer->tokenizeQuery('television')->allTermsWithVariants());
    }

    public function testSynonymsAreNotChainedRecursively(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withSynonyms([
                'a' => ['b'],
                'b' => ['c'],
            ]),
        );

        $token = $this->findToken($tokenizer->tokenize('c'), 'c');

        $this->assertContains('b', $token->getVariants());
        $this->assertNotContains('a', $token->getVariants());
    }

    public function testSynonymsExpandDocumentTokens(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withSynonyms([
                'tv' => ['television'],
            ]),
        );

        $tokens = $tokenizer->tokenize('my television is broken');
        $token = $this->findToken($tokens, 'television');

        $this->assertContains('tv', $token->getVariants());
        $this->assertContains('tv', $tokens->allTermsWithVariants());
    }

    public function testSynonymChainsOffStemVariant(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()
                ->withLanguages(['en'])
                ->withSynonyms([
                    'go' => ['run'],
                ]),
        );

        $token = $this->findToken($tokenizer->tokenize('running fast'), 'running');

        $this->assertContains('run', $token->getVariants());
        $this->assertContains('go', $token->getVariants());
    }

    public function testSynonymDirectionIsOneWay(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withSynonyms([
                'tv' => ['television'],
            ]),
        );

        $token = $this->findToken($tokenizer->tokenize('watching tv tonight'), 'tv');

        $this->assertNotContains('television', $token->getVariants());
    }

    public function testSynonymsWorkWithTypoToleranceDisabled(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()
                ->withLanguages(['en'])
                ->withTypoTolerance(TypoTolerance::disabled())
                ->withSynonyms([
                    'tv' => ['television'],
                ]),
        );

        $token = $this->findToken($tokenizer->tokenize('my television is broken'), 'television');

        $this->assertContains('tv', $token->getVariants());
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
        $this->assertSame(
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
                'weiss',
                'von',
                'nichts',
                'nicht',
            ],
            $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.')
                ->allTermsWithVariants(),
        );
    }

    public function testTokenizeWithoutTypoToleranceDoesNotStem(): void
    {
        $tokenizer = $this->createTokenizer(
            Configuration::create()->withTypoTolerance(TypoTolerance::disabled()),
        );

        $this->assertSame(['name'], $tokenizer->tokenize('Name')->allTermsWithVariants());
    }

    public function testTokenizeWithPhrases(): void
    {
        $tokenizer = $this->createTokenizer();
        $this->assertSame(
            [
                'hallo',
                'mein',
                'name',
                'ist',
                'hase',
                'und',
                'ich',
                'weiss',
                'von',
                'nichts',
                'nicht',
            ],
            $tokenizer->tokenize('Hallo, mein "Name ist Hase" und ich weiß von nichts.')
                ->allTermsWithVariants(),
        );
    }

    /**
     * @return iterable<array-key, array<mixed>>
     */
    public static function tokenizationWithLanguageSubsetProvider(): iterable
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
                'weiss',
                'von',
                'nichts',
            ],
        ];
    }

    private function createTokenizer(Configuration|null $configuration = null): Tokenizer
    {
        $configuration ??= Configuration::create();
        $languageDetector = new NitotmLanguageDetector($configuration->getLanguages());

        $engine = $this->createMock(Engine::class);
        $engine
            ->method('getConfiguration')
            ->willReturn($configuration)
        ;

        return new Tokenizer($engine, $languageDetector);
    }

    private function findToken(TokenCollection $tokens, string $term): Token
    {
        foreach ($tokens->all() as $token) {
            if ($token->getTerm() === $term) {
                return $token;
            }
        }

        $this->fail(\sprintf('No token with term "%s" found. Terms: %s', $term, implode(', ', $tokens->allTerms())));
    }
}
