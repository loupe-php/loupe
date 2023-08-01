<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Filter;

use Loupe\Loupe\Exception\FilterFormatException;
use Loupe\Loupe\Internal\Filter\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public static function filterProvider(): \Generator
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
            'age = 42',
            [
                [
                    'attribute' => 'age',
                    'operator' => '=',
                    'value' => 42.0,
                ],
            ],
        ];

        yield 'Basic float filter' => [
            'age > 42.67',
            [
                [
                    'attribute' => 'age',
                    'operator' => '>',
                    'value' => 42.67,
                ],
            ],
        ];

        yield 'Greater than or equals filter' => [
            'age >= 42.67',
            [
                [
                    'attribute' => 'age',
                    'operator' => '>=',
                    'value' => 42.67,
                ],
            ],
        ];

        yield 'Smaller than or equals filter' => [
            'age <= 42.67',
            [
                [
                    'attribute' => 'age',
                    'operator' => '<=',
                    'value' => 42.67,
                ],
            ],
        ];

        yield 'Basic geo filter' => [
            '_geoRadius(location, 45.472735, 9.184019, 2000)',
            [
                [
                    'attribute' => 'location',
                    'lat' => 45.472735,
                    'lng' => 9.184019,
                    'distance' => 2000.0,
                ],
            ],
        ];

        yield 'Basic IN filter' => [
            "genres IN ('Drama', 'Action', 'Documentary')",
            [
                [
                    'attribute' => 'genres',
                    'operator' => 'IN',
                    'value' => [
                        'Drama',
                        'Action',
                        'Documentary',
                    ],
                ],
            ],
        ];

        yield 'Basic NOT IN filter' => [
            "genres NOT IN ('Drama', 'Action', 'Documentary')",
            [
                [
                    'attribute' => 'genres',
                    'operator' => 'NOT IN',
                    'value' => [
                        'Drama',
                        'Action',
                        'Documentary',
                    ],
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

    public static function invalidFilterProvider(): \Generator
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
            "Col 21: Error: Expected ',', got ')'",
        ];

        yield 'Missing ( for _geoRadius' => [
            '_geoRadius&location, 1.00, 2.00, 200)',
            "Col 10: Error: Expected '(', got '&'",
        ];

        yield 'Missing ) for _geoRadius' => [
            '_geoRadius(location, 1.00, 2.00, 200',
            "Col 33: Error: Expected ')', got end of string.",
        ];

        yield 'Missing comma for _geoRadius' => [
            '_geoRadius(location, 1.00 2.00, 200)',
            "Col 26: Error: Expected ',', got '2.00'",
        ];

        yield 'Unclosed IN ()' => [
            "genres IN ('Action', 'Music'",
            "Col 21: Error: Expected ')",
        ];

        yield 'Unopened IN ()' => [
            "genres IN 'Action', 'Music')",
            "Col 10: Error: Expected '(', got 'Action'",
        ];

        yield 'Wrong contents in IN ()' => [
            'genres IN (_geoRadius(1.00, 2.00))',
            "Col 11: Error: Expected valid string or float value, got '_geoRadius'",
        ];

        yield 'Missing space between NOT and IN' => [
            "genres NOTIN ('Action', 'Music')",
            "Col 7: Error: Expected valid operator, got 'NOTIN'",
        ];

        yield 'NOT not before IN' => [
            'genres NOT 42',
            "Col 11: Error: Expected must be followed by IN (), got '42'",
        ];
    }

    public function testGeoDistanceNotFilterable(): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage("Col 11: Error: Expected filterable attribute, got 'location'");

        $parser = new Parser();
        $parser->getAst('_geoRadius(location, 45.472735, 9.184019, 2000)', ['gender']);
    }

    #[DataProvider('invalidFilterProvider')]
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

    #[DataProvider('filterProvider')]
    public function testValidFilter(string $filter, array $expectedAst): void
    {
        $parser = new Parser();

        $this->assertSame($expectedAst, $parser->getAst($filter)->toArray());
    }
}
