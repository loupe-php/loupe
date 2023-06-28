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

        yield 'Integer' => [42, LoupeTypes::TYPE_NUMBER];

        yield 'Float' => [42.42, LoupeTypes::TYPE_NUMBER];

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
    }

    #[DataProvider('getTypeFromValueProvider')]
    public function testGetTypeFromValue(mixed $value, string $expectedType): void
    {
        $this->assertSame($expectedType, LoupeTypes::getTypeFromValue($value));
    }
}
