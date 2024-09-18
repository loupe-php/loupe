<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Filter;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\FilterFormatException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\SearchParameters;
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

        yield 'Special string filter' => [
            'foobar = ' . SearchParameters::escapeFilterValue("The 17\" O'Conner && O`Series \n OR a || 1%2 book?"),
            [
                [
                    'attribute' => 'foobar',
                    'operator' => '=',
                    'value' => "The 17\" O'Conner && O`Series \n OR a || 1%2 book?",
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

        yield 'Basic boolean filter' => [
            'age = false',
            [
                [
                    'attribute' => 'age',
                    'operator' => '=',
                    'value' => false,
                ],
            ],
        ];

        yield 'Basic not equals filter' => [
            "genres != 'Drama'",
            [
                [
                    'attribute' => 'genres',
                    'operator' => '!=',
                    'value' => 'Drama',
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

        yield 'IS NULL filter' => [
            'age IS NULL',
            [
                [
                    'attribute' => 'age',
                    'operator' => '=',
                    'value' => LoupeTypes::VALUE_NULL,
                ],
            ],
        ];

        yield 'IS NOT NULL filter' => [
            'age IS NOT NULL',
            [
                [
                    'attribute' => 'age',
                    'operator' => '!=',
                    'value' => LoupeTypes::VALUE_NULL,
                ],
            ],
        ];

        yield 'IS EMPTY filter' => [
            'age IS EMPTY',
            [
                [
                    'attribute' => 'age',
                    'operator' => '=',
                    'value' => LoupeTypes::VALUE_EMPTY,
                ],
            ],
        ];

        yield 'IS NOT EMPTY filter' => [
            'age IS NOT EMPTY',
            [
                [
                    'attribute' => 'age',
                    'operator' => '!=',
                    'value' => LoupeTypes::VALUE_EMPTY,
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

        yield 'Basic geo filter with negative coordinates' => [
            '_geoRadius(location, -34.5567580, -58.4153774, 2000)',
            [
                [
                    'attribute' => 'location',
                    'lat' => -34.5567580,
                    'lng' => -58.4153774,
                    'distance' => 2000.0,
                ],
            ],
        ];

        yield 'Basic geo bounding box' => [
            '_geoBoundingBox(location, 53.3498, 23.7275, 37.9838, -6.2603)',
            [
                [
                    'attribute' => 'location',
                    'north' => 53.3498,
                    'east' => 23.7275,
                    'south' => 37.9838,
                    'west' => -6.2603,
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

        yield 'IN filter at the end of a group' => [
            "(genres IN ('Drama', 'Action') OR genres IN ('Documentary'))",
            [
                [
                    [
                        'attribute' => 'genres',
                        'operator' => 'IN',
                        'value' => [
                            'Drama',
                            'Action',
                        ],
                    ],
                    ['OR'],
                    [
                        'attribute' => 'genres',
                        'operator' => 'IN',
                        'value' => [
                            'Documentary',
                        ],
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
            "(genres > 42 AND genres < 50 OR genres IS NULL) OR foobar = 'test'",
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
                    ['OR'],
                    [
                        'attribute' => 'genres',
                        'operator' => '=',
                        'value' => LoupeTypes::VALUE_NULL,
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

        yield 'Pointless nested groups' => [
            "(((genres > 42 AND genres < 50 OR (genres IS NULL)) OR foobar = 'test'))",
            [
                [

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
                            ['OR'],
                            [
                                [
                                    'attribute' => 'genres',
                                    'operator' => '=',
                                    'value' => LoupeTypes::VALUE_NULL,
                                ],
                            ],
                        ],
                        ['OR'],
                        [
                            'attribute' => 'foobar',
                            'operator' => '=',
                            'value' => 'test',
                        ],
                    ],
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
            "Col 11: Error: Expected filterable attribute, got '1.00'",
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

        yield 'Invalid number of parameters for _geoBoundingBox no attribute' => [
            '_geoBoundingBox(1.00, 2.00, 2.00, 3.00)',
            "Col 16: Error: Expected filterable attribute, got '1.00'",
        ];

        yield 'Invalid number of parameters for _geoBoundingBox missing parameter' => [
            '_geoBoundingBox(location, 1.00, 2.00, 2.00)',
            "Col 42: Error: Expected ',', got ')'",
        ];

        yield 'Invalid number of parameters for _geoBoundingBox to much parameters' => [
            '_geoBoundingBox(location, 1.00, 2.00, 2.00, 3.00, 4.00)',
            "Col 48: Error: Expected ')', got ','",
        ];

        yield 'Missing ( for _geoBoundingBox' => [
            '_geoBoundingBox&location, 1.00, 2.00, 2.00, 3.00)',
            "Col 15: Error: Expected '(', got '&'",
        ];

        yield 'Missing ) for _geoBoundingBox' => [
            '_geoBoundingBox(location, 1.00, 2.00, 2.00, 3.00',
            "Col 44: Error: Expected ')', got end of string.",
        ];

        yield 'Missing comma for _geoBoundingBox' => [
            '_geoBoundingBox(location, 1.00 2.00, 2.00, 3.00)',
            "Col 31: Error: Expected ',', got '2.00'",
        ];

        yield 'Invalid coordinates for _geoBoundingBox latitude' => [
            '_geoBoundingBox(location, 1.0, 2.00, 92.00, 3.00)',
            "Col 16: Error: Expected Latitude value must be numeric -90.0 .. +90.0 (given: 92), got 'location, 1, 2, 92, 3'",
        ];

        yield 'Invalid coordinates for _geoBoundingBox longitude' => [
            '_geoBoundingBox(location, 1.0, 182.00, 2.00, 3.00)',
            "Col 16: Error: Expected Longitude value must be numeric -180.0 .. +180.0 (given: 182), got 'location, 1, 182, 2, 3'",
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
            "Col 11: Error: Expected valid string, float or boolean value, got '_geoRadius'",
        ];

        yield 'Missing space between NOT and IN' => [
            "genres NOTIN ('Action', 'Music')",
            "Col 7: Error: Expected valid operator, got 'NOTIN'",
        ];

        yield 'NOT not before IN' => [
            'genres NOT 42',
            "Col 11: Error: Expected must be followed by IN (), got '42'",
        ];

        yield 'IS with nonsense' => [
            'genres IS foobar',
            'Col 10: Error: Expected "NULL", "NOT NULL", "EMPTY" or "NOT EMPTY" after is, got \'foobar\'',
        ];

        yield 'IS NOT with nonsense' => [
            'genres IS NOT foobar',
            'Col 10: Error: Expected "NULL", "NOT NULL", "EMPTY" or "NOT EMPTY" after is, got \'NOT\'',
        ];
    }

    public function testGeoDistanceNotFilterable(): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage("Col 11: Error: Expected filterable attribute, got 'location'");

        $parser = new Parser();
        $engine = $this->mockEngine(['gender']);
        $parser->getAst('_geoRadius(location, 45.472735, 9.184019, 2000)', $engine);
    }

    #[DataProvider('invalidFilterProvider')]
    public function testInvalidFilter(string $filter, string $expectedMessage): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage($expectedMessage);

        $parser = new Parser();
        $engine = $this->mockEngine(['location', 'gender', 'attribute', 'genres', 'foobar']);
        $parser->getAst($filter, $engine);
    }

    public function testNonFilterableAttribute(): void
    {
        $this->expectException(FilterFormatException::class);
        $this->expectExceptionMessage("Col 0: Error: Expected filterable attribute, got 'genres'");

        $parser = new Parser();
        $engine = $this->mockEngine(['gender']);
        $parser->getAst('genres > 42.67', $engine);
    }

    /**
     * @param array<mixed> $expectedAst
     */
    #[DataProvider('filterProvider')]
    public function testValidFilter(string $filter, array $expectedAst): void
    {
        $parser = new Parser();
        $engine = $this->mockEngine(['location', 'genres', 'age', 'foobar']);

        $this->assertSame($expectedAst, $parser->getAst($filter, $engine)->toArray());
    }

    /**
     * @param array<string> $filterableAttributes
     */
    private function mockEngine(array $filterableAttributes): Engine
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes($filterableAttributes)
        ;
        $engine = $this->createMock(Engine::class);
        $engine
            ->method('getConfiguration')
            ->willReturn($configuration)
        ;

        return $engine;
    }
}
