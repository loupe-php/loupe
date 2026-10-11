<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

class Util
{
    /**
     * This is a slightly more memory-efficient alternative to array_chunk().
     *
     * @template T
     *
     * @param array<T> $array
     *
     * @return \Generator<array<T>>
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
            ++$count;

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

    public static function log(float $num): float
    {
        return log($num);
    }
}
