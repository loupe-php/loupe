<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal;

use Loupe\Loupe\Exception\InvalidJsonException;
use Loupe\Loupe\Internal\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase
{
    public function testDecodeJson(): void
    {
        $this->assertSame(
            ['id' => 1, 'nested' => ['value', true, null]],
            Util::decodeJson('{"id":1,"nested":["value",true,null]}'),
        );
    }

    public function testDecodeJsonWithDecoder(): void
    {
        $decoder = static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['id' => 2], Util::decodeJson('{"id":2}', $decoder));
    }

    public function testDecodeJsonWithDecoderReturningNonArray(): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('JSON must decode to an array.');

        Util::decodeJson('null', static fn (string $json): array|null => null);
    }

    #[DataProvider('provideInvalidJson')]
    public function testDecodeJsonRejectsInvalidValues(string $json): void
    {
        $this->expectException(InvalidJsonException::class);

        Util::decodeJson($json);
    }

    #[DataProvider('provideScalarJson')]
    public function testDecodeJsonScalarReturnsClearMessage(string $json): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('JSON must decode to an array.');

        Util::decodeJson($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidJson(): iterable
    {
        yield 'malformed' => ['{"id":'];
        yield 'null' => ['null'];
        yield 'scalar' => ['1'];
        yield 'string' => ['"value"'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideScalarJson(): iterable
    {
        yield 'null' => ['null'];
        yield 'integer' => ['123'];
        yield 'string' => ['"value"'];
        yield 'boolean' => ['true'];
    }
}
