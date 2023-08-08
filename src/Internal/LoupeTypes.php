<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

class LoupeTypes
{
    public const TYPE_ARRAY_EMPTY = 'array';

    public const TYPE_ARRAY_NUMBER = 'array<number>';

    public const TYPE_ARRAY_STRING = 'array<string>';

    public const TYPE_GEO = 'geo';

    public const TYPE_NULL = 'null';

    public const TYPE_NUMBER = 'number';

    public const TYPE_STRING = 'string';

    public static function convertToString(mixed $attributeValue): string
    {
        if (\is_string($attributeValue)) {
            return $attributeValue;
        }

        if (\is_array($attributeValue)) {
            if (array_is_list($attributeValue)) {
                sort($attributeValue);
            } else {
                ksort($attributeValue);
            }

            $strings = [];
            foreach ($attributeValue as $k => $v) {
                $recursive = self::convertToString($v);
                if ($recursive !== '') {
                    $strings[] = $k . '.' . self::convertToString($v);
                }
            }

            return implode('.', $strings);
        }

        // Ignore objects
        if (\is_object($attributeValue)) {
            return '';
        }

        return (string) $attributeValue;
    }

    public static function convertValueToType(mixed $attributeValue, string $type): array|string|float|null
    {
        if ($attributeValue === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_NULL => null,
            self::TYPE_STRING => self::convertToString($attributeValue),
            self::TYPE_NUMBER => self::convertToFloat($attributeValue),
            self::TYPE_ARRAY_STRING => self::convertToArrayOfStrings($attributeValue),
            self::TYPE_ARRAY_NUMBER, self::TYPE_GEO => self::convertToArrayOfFloats($attributeValue),
            default => throw new \InvalidArgumentException('Invalid type given.')
        };
    }

    public static function getTypeFromValue(mixed $variable): string
    {
        if ($variable === null) {
            return self::TYPE_NULL;
        }

        if (\is_float($variable) || \is_int($variable)) {
            return self::TYPE_NUMBER;
        }

        if (\is_array($variable)) {
            $count = \count($variable);
            $keys = array_keys($variable);

            if ($count === 0) {
                return self::TYPE_ARRAY_EMPTY;
            }

            if ($count === 2 && \in_array('lat', $keys, true) && \in_array('lng', $keys, true)) {
                return self::TYPE_GEO;
            }

            $allNumbers = true;
            foreach ($variable as $v) {
                $type = self::getTypeFromValue($v);

                if ($type !== self::TYPE_NUMBER) {
                    $allNumbers = false;
                }
            }

            if ($allNumbers) {
                return self::TYPE_ARRAY_NUMBER;
            }

            // Everything else will be converted to a string
            return self::TYPE_ARRAY_STRING;
        }

        // Everything else will be converted to a string
        return self::TYPE_STRING;
    }

    public static function isSingleType(string $type): bool
    {
        // The Geo type is not exactly a single type, but it has to be treated as such
        return \in_array($type, [self::TYPE_NUMBER, self::TYPE_STRING, self::TYPE_GEO], true);
    }

    public static function typeMatchesType(string $schemaType, string $checkType): bool
    {
        if ($checkType === self::TYPE_NULL || $schemaType === self::TYPE_NULL) {
            return true;
        }

        if ($schemaType === $checkType) {
            return true;
        }

        if ($checkType === self::TYPE_ARRAY_EMPTY) {
            return $schemaType === self::TYPE_ARRAY_NUMBER || $schemaType === self::TYPE_ARRAY_STRING;
        }

        return false;
    }

    private static function convertToArrayOfFloats(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToFloat($v);
        }

        return $result;
    }

    private static function convertToArrayOfStrings(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToString($v);
        }

        return $result;
    }

    private static function convertToFloat(mixed $attributeValue): float
    {
        if (\is_float($attributeValue)) {
            return $attributeValue;
        }

        if (\is_int($attributeValue)) {
            return (float) $attributeValue;
        }

        if (\is_string($attributeValue)) {
            return (float) $attributeValue;
        }

        return 0;
    }
}
