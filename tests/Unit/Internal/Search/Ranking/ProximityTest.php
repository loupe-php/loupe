<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Sorting\Relevance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProximityTest extends TestCase
{
    public static function proximityFactorProvider(): \Generator
    {
        yield 'All terms are adjacent' => [
            [[[1, 'term']], [[2, 'term']], [[3, 'term']]],
            0.1,
            1.0, // All distances are 1, so result is 1
        ];

        yield 'Non-adjacent terms' => [
            [[[1, 'term']], [[3, 'term']], [[5, 'term']]],
            0.1,
            (exp(-0.1 * 2) + exp(-0.1 * 2)) / 2,
        ];

        yield 'Empty positions' => [
            [],
            0.1,
            1.0, // No pairs, so result is 1 (shouldn't happen anyway)
        ];

        yield 'Single term' => [
            [[[1, 'term']]],
            0.1,
            1.0, // One match, must be 1
        ];

        yield 'Multiple positions per term, only closest must be considered' => [
            [[[1, 'term'], [4, 'term']], [[6, 'term'], [10, 'term']]],
            0.1,
            (exp(-0.1 * 5)),
        ];

        yield 'Higher decay factor' => [
            [[[1, 'term']], [[4, 'term']]],
            0.5,
            exp(-0.5 * 3), // Only one pair, distance is 3
        ];

        yield 'Lots of terms but all in the correct order' => [
            [[[1, 'term'], [7, 'term'], [12, 'term']], [[2, 'term'], [7, 'term']],  [[3, 'term'], [5, 'term'], [8, 'term'], [19, 'term'], [28, 'term']], [[4, 'term']], [[3, 'term'], [5, 'term'], [8, 'term'], [19, 'term'], [28, 'term']], [[6, 'term']], [[2, 'term'], [7, 'term']], [[3, 'term'], [5, 'term'], [8, 'term'], [19, 'term'], [28, 'term']], [[9, 'term']], [[10, 'term']]],
            0.1,
            1,
        ];
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm $positionsPerTerm
     */
    #[DataProvider('proximityFactorProvider')]
    public function testCalculateProximityFactor(array $positionsPerTerm, float $decayFactor, float $expected): void
    {
        $this->assertSame($expected, Proximity::calculateProximity($positionsPerTerm, $decayFactor));
    }
}
