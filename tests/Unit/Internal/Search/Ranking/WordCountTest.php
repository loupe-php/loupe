<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WordCountTest extends TestCase
{
    #[DataProvider('wordCountFactorProvider')]
    public function testWordCountCalculation(string $positionsPerTerm, float $expected): void
    {
        $rankingInfo = RankingInfo::fromQueryFunction('', '', $positionsPerTerm);
        $this->assertSame($expected, WordCount::calculate($rankingInfo));
    }

    public static function wordCountFactorProvider(): \Generator
    {
        yield 'No terms match' => [
            '0;0;0',
            0,
        ];

        yield 'One of three terms matches' => [
            '1:title:1;0;0',
            1 / 3,
        ];

        yield 'Two of three terms match' => [
            '1:title:1;2:summary:1;0',
            2 / 3,
        ];

        yield 'All terms match' => [
            '1:title:1;2:summary:1;3:summary:1',
            1,
        ];
    }
}
