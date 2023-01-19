<?php

namespace Terminal42\Loupe\Internal;

use Terminal42\Loupe\Exception\InvalidDocumentException;
use Terminal42\Loupe\Exception\InvalidJsonException;

class Util
{
    public static function getTypeFromVariable(mixed $variable): string
    {
        if (is_float($attributeValue) || is_string($attributeValue)) {
            return $attributeValue;
        }

        if (is_int($attributeValue)) {
            return (float) $attributeValue;
        }
    }




    public static function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);

        if (false === $json) {
            throw new InvalidJsonException(json_last_error());
        }

        return $json;
    }
}

