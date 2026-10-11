<?php

declare(strict_types=1);

namespace Loupe\Loupe\Json;

use Loupe\Loupe\Exception\InvalidJsonException;

interface JsonInterface
{
    /**
     * @return array<mixed>
     *
     * @throws InvalidJsonException If the JSON is invalid or does not decode to an array
     */
    public function decode(string $json): array;

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJsonException If encoding fails
     */
    public function encode(array $data, int $flags = 0): string;

    public function validate(string $json): bool;
}
