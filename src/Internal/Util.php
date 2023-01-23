<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Terminal42\Loupe\Exception\InvalidJsonException;

class Util
{
    public static function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);

        if ($json === false) {
            throw new InvalidJsonException(json_last_error());
        }

        return $json;
    }
}
