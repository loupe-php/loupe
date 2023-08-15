<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Loupe\Loupe\Exception\InvalidJsonException;

class Util
{
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
