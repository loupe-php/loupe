<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Loupe\Loupe\Exception\InvalidJsonException;

class Util
{
    /**
     * This is a slightly more memory-efficient alternative to array_chunk().
     * @param non-empty-array<array<mixed>> $array
     * @return \Generator<non-empty-list<array<mixed>>>
     */
    public static function arrayChunk(array $array, int $size): \Generator
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than 0.');
        }

        $chunk = [];
        $count = 0;

        foreach ($array as $value) {
            $chunk[] = $value;
            $count++;

            if ($count === $size) {
                yield $chunk;
                $chunk = [];
                $count = 0;
            }
        }

        if ($count > 0) {
            yield $chunk;
        }
    }

    /**
     * @return array<mixed>
     */
    public static function decodeJson(string $data): array
    {
        $data = json_decode($data, true);

        if (!\is_array($data)) {
            throw new InvalidJsonException(json_last_error_msg());
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);

        if ($json === false) {
            throw new InvalidJsonException(json_last_error_msg());
        }

        return $json;
    }

    public static function log(float $num): float
    {
        return log($num);
    }
}
