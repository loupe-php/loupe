<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Search\Sorting\Relevance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RelevanceTest extends TestCase
{
    public static function proximityFactorProvider(): \Generator
    {
        yield 'All terms are adjacent' => [
            [[1], [2], [3]],
            0.1,
            1.0, // All distances are 1, so result is 1
        ];

        yield 'Non-adjacent terms' => [
            [[1], [3], [5]],
            0.1,
            (exp(-0.1 * 2) + exp(-0.1 * 2)) / 2,
        ];

        yield 'Empty positions' => [
            [],
            0.1,
            1.0, // No pairs, so result is 1 (shouldn't happen anyway)
        ];

        yield 'Single term' => [
            [[1]],
            0.1,
            1.0, // One match, must be 1
        ];

        yield 'Multiple positions per term, only closest must be considered' => [
            [[1, 4], [6, 10]],
            0.1,
            (exp(-0.1 * 5)),
        ];

        yield 'Higher decay factor' => [
            [[1], [4]],
            0.5,
            exp(-0.5 * 3), // Only one pair, distance is 3
        ];

        yield 'Lots of terms but all in the correct order' => [
            [[1, 7, 12], [2, 7],  [3, 5, 8, 19, 28], [4], [3, 5, 8, 19, 28], [6], [2, 7], [3, 5, 8, 19, 28], [9], [10]],
            0.1,
            1,
        ];
    }

    public static function attributeWeightProvider(): \Generator
    {
        yield 'No attributes are weighted' => [
            [[[1, 'title'], [2, 'summary']]],
            [],
            1.0,
        ];

        yield 'No attributes are matched' => [
            [[[1, 'title'], [2, 'summary']]],
            ['unknown_attribute' => 5],
            1.0,
        ];

        yield 'All attributes are equal' => [
            [[[1, 'title'], [2, 'summary']]],
            ['title' => 1, 'summary' => 1],
            1.0,
        ];

        yield 'Attribute weighs double against attribute' => [
            [[[1, 'title'], [2, 'summary']]],
            ['title' => 2],
            2.0,
        ];

        yield 'Attribute weighs triple against multiple attributes' => [
            [[[1, 'title'], [2, 'summary'], [2, 'content']]],
            ['summary' => 3],
            3.0,
        ];
    }

    /**
     * @param array<int, array<int>> $positionsPerTerm
     * @param array<string, int> $attributeWeights
     */
    #[DataProvider('attributeWeightProvider')]
    public function testCalculateAttributeWeight(array $positionsPerTerm, array $attributeWeights, float $expected): void
    {
        $this->assertSame($expected, Relevance::calculateAttributeWeightFactor($positionsPerTerm, $attributeWeights));
    }

    /**
     * @param array<int, array<int>> $positionsPerTerm
     */
    #[DataProvider('proximityFactorProvider')]
    public function testCalculateProximityFactor(array $positionsPerTerm, float $decayFactor, float $expected): void
    {
        $this->assertSame($expected, Relevance::calculateProximityFactor($positionsPerTerm, $decayFactor));
    }
}
