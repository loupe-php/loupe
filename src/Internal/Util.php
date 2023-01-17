<?php

namespace Terminal42\Loupe\Internal;

use Terminal42\Loupe\Exception\InvalidAttributeNameException;

class Util
{
    public static function validateAttributeName(string $name): void
    {
        if (strlen($name) > InvalidAttributeNameException::MAX_ATTRIBUTE_NAME_LENGTH
            || !preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
        ) {
            throw InvalidAttributeNameException::becauseFormatDoesNotMatch($name);
        }
    }

    public static function convertToStringOrFloat(mixed $attributeValue): string|float
    {
        if (is_float($attributeValue) || is_string($attributeValue)) {
            return $attributeValue;
        }

        if (is_int($attributeValue)) {
            return (float) $attributeValue;
        }

        if (is_array($attributeValue)) {
            return 'array';
        }

        return (string) $attributeValue;
    }
}

