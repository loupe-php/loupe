<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal;

use Loupe\Loupe\Internal\LoupeTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoupeTypesTest extends TestCase
{
    public static function getTypeFromValueProvider(): \Generator
    {
        yield 'String' => ['foobar', LoupeTypes::TYPE_STRING];

        yield 'Stringable object' => [new class() implements \Stringable {
            public function __toString()
            {
                return 'foobar';
            }
        }, LoupeTypes::TYPE_STRING];

        yield 'Integer' => [42, LoupeTypes::TYPE_NUMBER];

        yield 'Float' => [42.42, LoupeTypes::TYPE_NUMBER];

        yield 'Boolean' => [true, LoupeTypes::TYPE_BOOLEAN];

        yield 'Array of strings' => [['foobar', 'foobar2'], LoupeTypes::TYPE_ARRAY_STRING];

        yield 'Array of integers' => [[42, 84], LoupeTypes::TYPE_ARRAY_NUMBER];

        yield 'Empty array' => [[], LoupeTypes::TYPE_ARRAY_EMPTY];

        yield 'Mixed array' => [[42, 'foobar'], LoupeTypes::TYPE_ARRAY_STRING];

        yield 'Correct geo value as strings' => [
            [
                'lat' => '0.0',
                'lng' => '1.0',
            ],
            LoupeTypes::TYPE_GEO,
        ];

        yield 'Correct geo value as floats' => [
            [
                'lat' => 0.0,
                'lng' => 1.0,
            ],
            LoupeTypes::TYPE_GEO,
        ];

        yield 'Incorrect geo values will just end up being an array of strings' => [
            [
                'lat' => '0.0',
                'longitude' => '1.0',
            ],
            LoupeTypes::TYPE_ARRAY_STRING,
        ];
    }

    public function testConvertToString(): void
    {
        $this->assertSame('foobar.array.0.42.1.foo.integer.1.string.other.whatever.foo', LoupeTypes::convertToString([
            'foobar' => [
                'integer' => 1,
                'string' => 'other',
                'array' => ['foo', 42],
                'object' => new \stdClass(),
            ],
            'whatever' => 'foo',
        ]));

        $this->assertSame('foobar', LoupeTypes::convertToString(
            new class() implements \Stringable {
                public function __toString()
                {
                    return 'foobar';
                }
            }
        ));
    }

    #[DataProvider('getTypeFromValueProvider')]
    public function testGetTypeFromValue(mixed $value, string $expectedType): void
    {
        $this->assertSame($expectedType, LoupeTypes::getTypeFromValue($value));
    }

    #[DataProvider('typeIsNarrowerThanType')]
    public function testTypeIsNarrowerThanType(string $schemaType, string $checkType, bool $expectedResult): void
    {
        $this->assertSame($expectedResult, LoupeTypes::typeIsNarrowerThanType($schemaType, $checkType));
    }

    #[DataProvider('typeMatchesTypeProvider')]
    public function testTypeMatchesType(string $schemaType, string $checkType, bool $expectedResult): void
    {
        $this->assertSame($expectedResult, LoupeTypes::typeMatchesType($schemaType, $checkType));
    }

    public static function typeIsNarrowerThanType(): \Generator
    {
        yield [LoupeTypes::TYPE_NULL, LoupeTypes::TYPE_NULL, false];
        yield [LoupeTypes::TYPE_NULL, LoupeTypes::TYPE_NUMBER, true];
        yield [LoupeTypes::TYPE_NUMBER, LoupeTypes::TYPE_GEO, false];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_EMPTY, false];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_NUMBER, true];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_STRING, true];
    }

    public static function typeMatchesTypeProvider(): \Generator
    {
        yield [LoupeTypes::TYPE_NULL, LoupeTypes::TYPE_NULL, true];
        yield [LoupeTypes::TYPE_NUMBER, LoupeTypes::TYPE_NUMBER, true];
        yield [LoupeTypes::TYPE_GEO, LoupeTypes::TYPE_GEO, true];
        yield [LoupeTypes::TYPE_BOOLEAN, LoupeTypes::TYPE_BOOLEAN, true];
        yield [LoupeTypes::TYPE_STRING, LoupeTypes::TYPE_STRING, true];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_EMPTY, true];
        yield [LoupeTypes::TYPE_ARRAY_NUMBER, LoupeTypes::TYPE_ARRAY_NUMBER, true];
        yield [LoupeTypes::TYPE_ARRAY_STRING, LoupeTypes::TYPE_ARRAY_STRING, true];
        yield [LoupeTypes::TYPE_ARRAY_STRING, LoupeTypes::TYPE_ARRAY_EMPTY, true];
        yield [LoupeTypes::TYPE_ARRAY_NUMBER, LoupeTypes::TYPE_ARRAY_EMPTY, true];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_STRING, true];
        yield [LoupeTypes::TYPE_ARRAY_EMPTY, LoupeTypes::TYPE_ARRAY_NUMBER, true];
    }
}
