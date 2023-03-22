<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit\Internal\Filter;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Exception\FilterFormatException;
use Terminal42\Loupe\Internal\Filter\Parser;

class ParserTest extends TestCase
{
    public function filterProvider(): \Generator
    {
        yield 'Basic string filter' => [
            "genres = 'Drama'",
            [
                [
                    'attribute' => 'genres',
                    'operator' => '=',
                    'value' => 'Drama',
                ],
            ],
        ];

        yield 'Basic integer filter' => [
            'genres = 42',
            [
                [
                    'attribute' => 'genres',
                    'operator' => '=',
                    'value' => 42.0,
                ],
            ],
        ];

        yield 'Basic float filter' => [
            'genres > 42.67',
            [
                [
                    'attribute' => 'genres',
                    'operator' => '>',
                    'value' => 42.67,
                ],
            ],
        ];

        yield 'Basic geo filter' => [
            '_geoRadius(45.472735, 9.184019, 2000)',
            [
                [
                    'lat' => 45.472735,
                    'lng' => 9.184019,
                    'distance' => 2000.0,
                ],
            ],
        ];

        yield 'Combined filters with greater and smaller than operators' => [
            'genres > 42 AND genres < 50',
            [
                [
                    'attribute' => 'genres',
                    'operator' => '>',
                    'value' => 42.0,
                ],
                ['AND'],
                [
                    'attribute' => 'genres',
                    'operator' => '<',
                    'value' => 50.0,
                ],
            ],
        ];

        yield 'Combined filters with groups' => [
            "(genres > 42 AND genres < 50) OR foobar = 'test'",
            [
                [
                    [
                        'attribute' => 'genres',
                        'operator' => '>',
                        'value' => 42.0,
                    ],
                    ['AND'],
                    [
                        'attribute' => 'genres',
                        'operator' => '<',
                        'value' => 50.0,
                    ],
                ],
                ['OR'],
                [
                    'attribute' => 'foobar',
                    'operator' => '=',
                    'value' => 'test',
                ],
            ],
        ];
    }

    public function invalidFilterProvider(): \Generator
    {
        yield 'Must begin with either ( or an attribute name' => [
            '$whatever',
            "Col 0: Error: Expected an attribute name, _geoRadius() or '(', got '$'",
        ];

        yield 'Attribute  name must be followed by operator' => [
            'attribute (',
            "Col 10: Error: Expected valid operator, got '('",
        ];

        yield 'Cannot close a non-opened group' => [
            'genres > 42 ) foobar < 60)',
            "Col 12: Error: Expected an opened group statement, got ')'",
        ];

        yield 'Missed closing the group' => [
            'genres > 42 AND (foobar < 60',
            'Col 26: Error: Expected a closing parenthesis, got end of string.',
        ];

        yield 'Invalid number of parameters for _geoRadius' => [
            '_geoRadius(1.00, 2.00)',
            'Col 21: Error: Expected ', ", got ')'",
        ];

        yield 'Missing ( for _geoRadius' => [
            '_geoRadius&1.00, 2.00, 200)',
            "Col 10: Error: Expected '(', got '&'",
        ];

        yield 'Missing ) for _geoRadius' => [
            '_geoRadius(1.00, 2.00, 200',
            "Col 23: Error: Expected ')', got end of string.",
        ];

        yield 'Missing comma for _geoRadius' => [
            '_geoRadius(1.00 2.00, 200)',
            "Col 16: Error: Expected ',', got '2.00'",
        ];
    }

    public function testGeoDistanceNotFilterable(): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage(
            'Cannot use "_geoRadius()" without having defined "_geo" as filterable attribute.'
        );

        $parser = new Parser();
        $parser->getAst('_geoRadius(45.472735, 9.184019, 2000)', ['gender']);
    }

    /**
     * @dataProvider invalidFilterProvider
     */
    public function testInvalidFilter(string $filter, string $expectedMessage): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage($expectedMessage);

        $parser = new Parser();
        $parser->getAst($filter);
    }

    public function testNonFilterableAttribute(): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage("Col 0: Error: Expected filterable attribute, got 'genres'");

        $parser = new Parser();
        $parser->getAst('genres > 42.67', ['gender']);
    }

    /**
     * @dataProvider filterProvider
     */
    public function testValidFilter(string $filter, array $expectedAst): void
    {
        $parser = new Parser();

        $this->assertSame($expectedAst, $parser->getAst($filter)->toArray());
    }
}
