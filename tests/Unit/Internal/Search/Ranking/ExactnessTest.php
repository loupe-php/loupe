<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\Exactness;
use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExactnessTest extends TestCase
{
    public static function exactnessFactorProvider(): \Generator
    {
        yield 'No terms match' => [
            '0;0;0',
            0.0,
        ];

        yield 'Zero typos means exact match' => [
            '1:title:0',
            1.0,
        ];

        yield 'One typo is not an exact match' => [
            '1:title:1',
            0,
        ];

        yield 'Five typos is also not an exact match' => [
            '1:title:5',
            0,
        ];

        yield 'One term matches exactly, the other does not' => [
            '1:title:0;3:title:3',
            0.5,
        ];
    }

    #[DataProvider('exactnessFactorProvider')]
    public function testTypoCountCalculation(string $positionsPerTerm, float $expected): void
    {
        $rankingInfo = RankingInfo::fromQueryFunction('', '', $positionsPerTerm);
        $this->assertSame($expected, Exactness::calculate($rankingInfo));
    }
}
