<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Internal\LoupeTypes;

class LoupeTypesTest extends TestCase
{
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
}
