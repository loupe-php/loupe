<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Search\Ranking\AttributeWeight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeWeightTest extends TestCase
{
    public static function attributeWeightProvider(): \Generator
    {
        yield 'No attributes are weighted' => [
            [[[1, 'title'], [2, 'summary']]],
            [],
            1.0,
        ];

        yield 'No attributes are matched' => [
            [[[1, 'title'], [2, 'summary']]],
            [
                'unknown_attribute',
            ],
            1.0,
        ];

        yield 'All attributes are equal' => [
            [[[1, 'title'], [2, 'summary']]],
            ['*'],
            1.0,
        ];

        yield 'Attributes are applied when found' => [
            [[[1, 'title']]],
            ['title', 'summary'],
            1.0,
        ];

        yield 'Attributes are applied when found later in list' => [
            [[[1, 'non_existent'], [2, 'summary']]],
            ['title', 'summary'],
            0.8,
        ];

        yield 'Terms found in multiple attributes are applied the highest factor' => [
            [[[1, 'title'], [2, 'summary']]],
            ['title', 'summary'],
            1.0,
        ];
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm $positionsPerTerm
     * @param array<string, int> $attributes
     */
    #[DataProvider('attributeWeightProvider')]
    public function testAttributeWeightCalculation(array $positionsPerTerm, array $attributes, float $expected): void
    {
        $totalQueryTokenCount = 2; // ignored by AttributeWeight ranker
        $this->assertSame($expected, AttributeWeight::calculate($attributes, $totalQueryTokenCount, $positionsPerTerm));
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
