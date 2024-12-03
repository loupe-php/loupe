<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Search\Ranking\WordCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WordCountTest extends TestCase
{
    public static function wordCountFactorProvider(): \Generator
    {
        yield 'No terms match' => [
            [[[0]], [[0]], [[0]]],
            3,
            0,
        ];

        yield 'One of three terms matches' => [
            [[[1, 'term1']], [[0]], [[0]]],
            3,
            1 / 3
        ];

        yield 'Two of three terms match' => [
            [[[1, 'term1']], [[2, 'term2']], [[0]]],
            3,
            2 / 3
        ];

        yield 'All terms match' => [
            [[[1, 'term1']], [[2, 'term2']], [[3, 'term3']]],
            3,
            1
        ];
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm $positionsPerTerm
     */
    #[DataProvider('wordCountFactorProvider')]
    public function testWordCountCalculation(array $positionsPerTerm, int $queryTokenCount, float $expected): void
    {
        $attributes = []; // Not used in the method
        $this->assertSame($expected, WordCount::calculate($attributes, $queryTokenCount, $positionsPerTerm));
    }
}
