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
            0,
        ];

        yield 'One of three terms matches' => [
            [[[1, 'title']], [[0]], [[0]]],
            1 / 3
        ];

        yield 'Two of three terms match' => [
            [[[1, 'title']], [[2, 'summary']], [[0]]],
            2 / 3
        ];

        yield 'All terms match' => [
            [[[1, 'title']], [[2, 'summary']], [[3, 'summary']]],
            1
        ];
    }

    /**
     * @param array<int, array<int, array{int, string|null}>> $positionsPerTerm $positionsPerTerm
     */
    #[DataProvider('wordCountFactorProvider')]
    public function testWordCountCalculation(array $positionsPerTerm, float $expected): void
    {
        $this->assertSame($expected, WordCount::calculateWordCount($positionsPerTerm));
    }
}
