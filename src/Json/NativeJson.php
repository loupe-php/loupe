<?php

declare(strict_types=1);

namespace Loupe\Loupe\Json;

use Loupe\Loupe\Exception\InvalidJsonException;

final class NativeJson implements JsonInterface
{
    public function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            throw new InvalidJsonException(JSON_ERROR_NONE === json_last_error() ? 'JSON must decode to an array.' : json_last_error_msg());
        }

        return $decoded;
    }

    public function encode(array $data, int $flags = 0): string
    {
        try {
            $json = json_encode($data, $flags);
        } catch (\JsonException $e) {
            throw new InvalidJsonException($e->getMessage(), $e->getCode(), $e);
        }

        if (false === $json) {
            throw new InvalidJsonException(json_last_error_msg());
        }

        return $json;
    }

    public function validate(string $json): bool
    {
        json_decode($json);

        return JSON_ERROR_NONE === json_last_error();
    }
}
