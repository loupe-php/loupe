<?php

declare(strict_types=1);

namespace Unit\Internal\Tokenizer;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;

class TokenizerTest extends TestCase
{
    public function testMaximumTokens(): void
    {
        $tokenizer = new Tokenizer(5);
        $tokens = $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.');

        $this->assertSame(5, $tokens->count());

        $this->assertSame([
            'hallo',
            'mein',
            'name',
            'nam',
            'ist',
            'hase',
            'has',
        ], $tokenizer->tokenize('Hallo, mein Name ist Hase und ich weiß von nichts.')
            ->allTokensWithVariants());
    }

    public function testTokenize(): void
    {
        $tokenizer = new Tokenizer(10);
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
            ->allTokensWithVariants());
    }
}
