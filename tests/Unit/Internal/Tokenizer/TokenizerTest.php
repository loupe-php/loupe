<?php

declare(strict_types=1);

namespace Unit\Internal\Tokenizer;

use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testMaximumTokens(): void
    {
        $tokenizer = new Tokenizer();
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

    public function testTokenize(): void
    {
        $tokenizer = new Tokenizer();
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
        $tokenizer = new Tokenizer();
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
}
