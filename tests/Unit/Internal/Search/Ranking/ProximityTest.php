<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\Proximity;
use Loupe\Loupe\Internal\Search\Ranking\TermPositions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProximityTest extends TestCase
{
    public static function proximityFactorProvider(): \Generator
    {
        yield 'All terms are adjacent' => [
            '1:attribute;2:attribute;3:attribute',
            0.1,
            1.0, // All distances are 1, so result is 1
        ];

        yield 'Non-adjacent terms' => [
            '1:attribute;3:attribute;5:attribute',
            0.1,
            (exp(-0.1 * 2) + exp(-0.1 * 2)) / 2,
        ];

        yield 'Empty positions' => [
            '',
            0.1,
            1.0, // No pairs, so result is 1 (shouldn't happen anyway)
        ];

        yield 'Single term' => [
            '1:attribute',
            0.1,
            1.0, // One match, must be 1
        ];

        yield 'Multiple positions per term, only closest must be considered' => [
            '1:attribute,4:attribute;6:attribute,10:attribute',
            0.1,
            (exp(-0.1 * 5)),
        ];

        yield 'Higher decay factor' => [
            '1:attribute;4:attribute',
            0.5,
            exp(-0.5 * 3), // Only one pair, distance is 3
        ];

        yield 'Lots of terms but all in the correct order' => [
            '1:attribute,7:attribute,12:attribute;2:attribute,7:attribute;3:attribute,5:attribute,8:attribute,19:attribute,28:attribute;4:attribute;3:attribute,5:attribute,8:attribute,19:attribute,28:attribute;6:attribute;2:attribute,7:attribute;3:attribute,5:attribute,8:attribute,19:attribute,28:attribute;9:attribute;10:attribute',
            0.1,
            1,
        ];

        yield 'Attributes must be considered' => [
            '1:attribute;2:other_attribute,3:attribute', // Here we searched for 2 terms and the second matched in "other_attribute" at position 2 and in "attribute" at position 3, so we have to test that the distance is 2 between attribute!
            0.1,
            exp(-0.1 * 2), // "other_attribute" is not relevant, the best match is in "attribute" and we have a distance of 2
        ];
    }

    #[DataProvider('proximityFactorProvider')]
    public function testProximityCalculation(string $positionsPerTerm, float $decayFactor, float $expected): void
    {
        $this->assertSame($expected, Proximity::calculateWithDecayFactor(TermPositions::fromQueryFunction($positionsPerTerm), $decayFactor));
    }
}
