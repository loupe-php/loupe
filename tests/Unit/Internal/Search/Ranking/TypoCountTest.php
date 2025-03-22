<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use Loupe\Loupe\Internal\Search\Ranking\TypoCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypoCountTest extends TestCase
{
    #[DataProvider('typoCountFactorProvider')]
    public function testTypoCountCalculation(string $positionsPerTerm, float $expected): void
    {
        $rankingInfo = RankingInfo::fromQueryFunction('', '', $positionsPerTerm);
        $this->assertSame($expected, TypoCount::calculate($rankingInfo));
    }

    public static function typoCountFactorProvider(): \Generator
    {
        yield 'No terms match' => [
            '0;0;0',
            1.0,
        ];

        yield 'Zero typo match' => [
            '1:title:0',
            1.0,
        ];

        yield 'One typo' => [
            '1:title:1',
            exp(-0.1 * 1),
        ];

        yield 'Always lowest amount of typos is considered' => [
            '1:title:1,2:summary:2;3:summary:1', // Two terms, the first one matched in "title" (1 typo) and "summary" (2 typos), the second in "summary" with 1 typo. So 2 typos in total.
            exp(-0.1 * 2),
        ];
    }
}
