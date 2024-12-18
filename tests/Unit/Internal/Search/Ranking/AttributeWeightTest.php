<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use Loupe\Loupe\Internal\Search\Ranking\RankingInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeWeightTest extends TestCase
{
    public static function attributeWeightProvider(): \Generator
    {
        yield 'No attributes are weighted' => [
            '1:title:1,2:summary:1',
            '',
            1.0,
        ];

        yield 'No attributes are matched' => [
            '1:title:1,2:summary:1',
            'unknown_attribute',
            1.0,
        ];

        yield 'All attributes are equal' => [
            '1:title:1,2:summary:1',
            '*',
            1.0,
        ];

        yield 'Attributes are applied when found' => [
            '1:title:1',
            'title:summary',
            1.0,
        ];

        yield 'Attributes are applied when found later in list' => [
            '1:non_existent:1,2:summary:1',
            'title:summary',
            0.8,
        ];

        yield 'Terms found in multiple attributes are applied the highest factor' => [
            '1:title:1,2:summary:1',
            'title:summary',
            1.0,
        ];
    }

    #[DataProvider('attributeWeightProvider')]
    public function testAttributeWeightCalculation(string $positionsPerTerm, string $searchableAttributes, float $expected): void
    {
        $this->assertSame($expected, AttributeWeight::calculate(RankingInfo::fromQueryFunction($searchableAttributes, '', $positionsPerTerm)));
    }

    public function testIntrinsicAttributeWeightCalculation(): void
    {
        $this->assertSame(
            [],
            AttributeWeight::calculateIntrinsicAttributeWeights(['*'])
        );

        $this->assertSame(
            [
                'title' => 1.0,
                'summary' => 0.8,
                'body' => 0.64,
            ],
            AttributeWeight::calculateIntrinsicAttributeWeights(['title', 'summary', 'body'])
        );
    }
}
