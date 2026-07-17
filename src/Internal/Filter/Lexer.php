<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter;

use Doctrine\Common\Lexer\AbstractLexer;
use Loupe\Loupe\Internal\Index\IndexInfo;

class Lexer extends AbstractLexer
{
    public const T_AND = 201;

    public const T_ATTRIBUTE_NAME = 100;

    public const T_BETWEEN = 21;

    public const T_CLOSE_PARENTHESIS = 6;

    public const T_COMMA = 8;

    public const T_EMPTY = 19;

    public const T_EQUALS = 11;

    public const T_FALSE = 7;

    public const T_FLOAT = 5;

    public const T_GEO_BOUNDING_BOX = 102;

    public const T_GEO_RADIUS = 101;

    public const T_GREATER_THAN = 12;

    public const T_IN = 14;

    public const T_IS = 17;

    public const T_LOWER_THAN = 13;

    public const T_MINUS = 20;

    public const T_NEGATE = 16;

    public const T_NONE = 1;

    public const T_NOT = 15;

    public const T_NULL = 18;

    public const T_OPEN_PARENTHESIS = 7;

    public const T_OR = 200;

    public const T_STRING = 3;

    public const T_TRUE = 6;

    protected function getCatchablePatterns()
    {
        return [
            '[a-z_\\\][a-z0-9_]*(?:\\\[a-z_][a-z0-9_]*)*', // identifier or qualified name
            '(?:[0-9]+(?:[\.][0-9]+)*)(?:e[+-]?[0-9]+)?', // numbers
            "'(?:[^']|'')*'", // quoted strings
        ];
    }

    protected function getNonCatchablePatterns()
    {
        return ['\s+', '(.)'];
    }

    protected function getType(mixed &$value): int
    {
        switch (true) {
            case is_numeric($value):
                return self::T_FLOAT;

            // Recognize quoted strings
            case "'" === $value[0]:
                $value = str_replace("''", "'", substr((string) $value, 1, \strlen((string) $value) - 2));

                return self::T_STRING;

            case 'AND' === $value:
            case '&&' === $value:
                return self::T_AND;

            case 'OR' === $value:
            case '||' === $value:
                return self::T_OR;

            case 'IN' === $value:
                return self::T_IN;

            case 'NOT' === $value:
                return self::T_NOT;

            case 'EMPTY' === $value:
                return self::T_EMPTY;

            case 'IS' === $value:
                return self::T_IS;

            case 'NULL' === $value:
                return self::T_NULL;

            case 'false' === $value:
                return self::T_FALSE;

            case 'true' === $value:
                return self::T_TRUE;

            case '-' === $value:
                return self::T_MINUS;

            case 'BETWEEN' === $value:
                return self::T_BETWEEN;

            case '_geoRadius' === $value:
                return self::T_GEO_RADIUS;

            case '_geoBoundingBox' === $value:
                return self::T_GEO_BOUNDING_BOX;

            // Attribute names
            case IndexInfo::isValidAttributeName($value):
                return self::T_ATTRIBUTE_NAME;

            case '(' === $value:
                return self::T_OPEN_PARENTHESIS;

            case ')' === $value:
                return self::T_CLOSE_PARENTHESIS;

            case '=' === $value:
                return self::T_EQUALS;

            case '>' === $value:
                return self::T_GREATER_THAN;

            case '<' === $value:
                return self::T_LOWER_THAN;

            case '!' === $value:
                return self::T_NEGATE;

            case ',' === $value:
                return self::T_COMMA;
        }

        return self::T_NONE;
    }
}
