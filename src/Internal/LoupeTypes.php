<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

class LoupeTypes
{
    public const TYPE_ARRAY_EMPTY = 'array';

    public const TYPE_ARRAY_NUMBER = 'array<number>';

    public const TYPE_ARRAY_STRING = 'array<string>';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_GEO = 'geo';

    public const TYPE_NULL = 'null';

    public const TYPE_NUMBER = 'number';

    public const TYPE_STRING = 'string';

    // Marker for IS EMPTY filters. Unfortunately, SQLite is loosely typed (strict tables only came
    // in later versions which are not supported by Doctrine DBAL anyway) and so we cannot work with real "0" (counting
    // entries of []) or real ''.
    public const VALUE_EMPTY = ':l:e';

    // Marker for IS NULL filters. Unfortunately, SQLite is loosely typed (strict tables only came
    // in later versions which are not supported by Doctrine DBAL anyway) and so we cannot work with real "null".
    public const VALUE_NULL = ':l:n';

    /**
     * @param  array<mixed> $attributeValue
     * @return array<float>
     */
    public static function convertToArrayOfFloats(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToFloat($v);
        }

        return $result;
    }

    /**
     * @param  array<mixed> $attributeValue
     * @return array<string>
     */
    public static function convertToArrayOfStrings(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToString($v);
        }

        return $result;
    }

    public static function convertToFloat(mixed $attributeValue): float
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

    public static function convertToString(mixed $attributeValue): string
    {
        if ($attributeValue instanceof \Stringable) {
            $attributeValue = (string) $attributeValue;
        }

        if (\is_string($attributeValue)) {
            if ($attributeValue === '') {
                return self::VALUE_EMPTY;
            }

            // Escape our internal values
            if (\in_array($attributeValue, [self::VALUE_EMPTY, self::VALUE_NULL], true)) {
                return '\\' . $attributeValue;
            }

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

    /**
     * @return array<string>|array<float>|string|float|bool
     */
    public static function convertValueToType(mixed $attributeValue, string $type): array|string|float|bool
    {
        if ($attributeValue === null) {
            return self::VALUE_NULL;
        }

        return match ($type) {
            self::TYPE_NULL => self::VALUE_NULL,
            self::TYPE_ARRAY_EMPTY => self::VALUE_EMPTY,
            self::TYPE_STRING => self::convertToString($attributeValue),
            self::TYPE_NUMBER => self::convertToFloat($attributeValue),
            self::TYPE_BOOLEAN => (bool) $attributeValue,
            self::TYPE_ARRAY_STRING => $attributeValue === [] ? self::VALUE_EMPTY : self::convertToArrayOfStrings($attributeValue),
            self::TYPE_ARRAY_NUMBER => $attributeValue === [] ? self::VALUE_EMPTY : self::convertToArrayOfFloats($attributeValue),
            self::TYPE_GEO => self::convertToArrayOfFloats($attributeValue),
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

        if (\is_bool($variable)) {
            return self::TYPE_BOOLEAN;
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

    public static function isFloatType(string $type): bool
    {
        return \in_array($type, [
            self::TYPE_NUMBER,
            self::TYPE_ARRAY_NUMBER,
        ], true);
    }

    public static function isMultiType(string $type): bool
    {
        return \in_array($type, [
            self::TYPE_ARRAY_EMPTY,
            self::TYPE_ARRAY_NUMBER,
            self::TYPE_STRING,
        ], true);
    }

    public static function isSingleType(string $type): bool
    {
        // The Geo type is not exactly a single type, but it has to be treated as such
        return \in_array($type, [
            self::TYPE_NUMBER,
            self::TYPE_STRING,
            self::TYPE_BOOLEAN,
            self::TYPE_GEO,
            self::TYPE_NULL,
        ], true);
    }

    public static function typeIsNarrowerThanType(string $schemaType, string $checkType): bool
    {
        if ($checkType === self::TYPE_NULL) {
            return false;
        }

        if ($schemaType === self::TYPE_NULL) {
            return true;
        }

        if ($schemaType !== self::TYPE_ARRAY_EMPTY) {
            return false;
        }

        if (\in_array($checkType, [self::TYPE_ARRAY_NUMBER, self::TYPE_ARRAY_STRING], true)) {
            return true;
        }

        return false;
    }

    public static function typeMatchesType(string $schemaType, string $checkType): bool
    {
        if ($checkType === self::TYPE_NULL || $schemaType === self::TYPE_NULL) {
            return true;
        }

        if ($schemaType === $checkType) {
            return true;
        }

        if ($checkType === self::TYPE_ARRAY_EMPTY && ($schemaType === self::TYPE_ARRAY_NUMBER || $schemaType === self::TYPE_ARRAY_STRING)) {
            return true;
        }

        if ($schemaType === self::TYPE_ARRAY_EMPTY && ($checkType === self::TYPE_ARRAY_NUMBER || $checkType === self::TYPE_ARRAY_STRING)) {
            return true;
        }

        return false;
    }
}
