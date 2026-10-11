<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Exception\InvalidJsonException;
use Loupe\Loupe\Json\NativeJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeJsonTest extends TestCase
{
    public function testDecode(): void
    {
        $this->assertSame(
            ['id' => 1, 'nested' => ['value', true, null]],
            (new NativeJson())->decode('{"id":1,"nested":["value",true,null]}'),
        );
    }

    #[DataProvider('provideInvalidJson')]
    public function testDecodeRejectsInvalidValues(string $json): void
    {
        $this->expectException(InvalidJsonException::class);

        (new NativeJson())->decode($json);
    }

    #[DataProvider('provideScalarJson')]
    public function testDecodeScalarReturnsClearMessage(string $json): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('JSON must decode to an array.');

        (new NativeJson())->decode($json);
    }

    public function testEncode(): void
    {
        $data = ['id' => 1, 'nested' => ['value', true, null]];

        $this->assertSame($data, json_decode((new NativeJson())->encode($data), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('{"value":"\u00e9"}', (new NativeJson())->encode(['value' => 'é']));
        $this->assertSame('{"value":"é"}', (new NativeJson())->encode(['value' => 'é'], JSON_UNESCAPED_UNICODE));
    }

    public function testEncodeRejectsInvalidValues(): void
    {
        $this->expectException(InvalidJsonException::class);

        (new NativeJson())->encode(['invalid' => "\xB1"]);
    }

    public function testEncodeWrapsJsonException(): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters');

        (new NativeJson())->encode(['invalid' => "\xB1"], JSON_THROW_ON_ERROR);
    }

    #[DataProvider('provideValidationCases')]
    public function testValidate(string $json, bool $valid): void
    {
        $this->assertSame($valid, (new NativeJson())->validate($json));
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

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideValidationCases(): iterable
    {
        yield 'object' => ['{"id":1}', true];
        yield 'array' => ['[1,2]', true];
        yield 'scalar' => ['null', true];
        yield 'malformed' => ['{"id":', false];
        yield 'invalid UTF-8' => ["\xB1", false];
    }
}
