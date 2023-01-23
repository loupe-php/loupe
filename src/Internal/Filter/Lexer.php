<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Filter;

use Doctrine\Common\Lexer\AbstractLexer;
use Terminal42\Loupe\Internal\Index\IndexInfo;

class Lexer extends AbstractLexer
{
    public const T_AND = 201;

    public const T_ATTRIBUTE_NAME = 100;

    public const T_CLOSE_PARENTHESIS = 6;

    // Operators are between 10 and 30
    public const T_EQUALS = 11;

    public const T_FLOAT = 5;

    public const T_GREATER_THAN = 12;

    public const T_LOWER_THAN = 13;

    public const T_NEGATE = 16;

    public const T_NONE = 1;

    public const T_OPEN_PARENTHESIS = 7;

    // All keyword tokens should be >= 200
    public const T_OR = 200;

    public const T_STRING = 3;

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
        return ['\s+'];
    }

    protected function getType(string &$value)
    {
        switch (true) {
            case is_numeric($value):
                return self::T_FLOAT;

                // Recognize quoted strings
            case $value[0] === "'":
                $value = str_replace("''", "'", substr($value, 1, strlen($value) - 2));

                return self::T_STRING;

            case $value === 'AND':
            case $value === '&&':
                return self::T_AND;

            case $value === 'OR':
            case $value === '||':
                return self::T_OR;

                // Attribute names
            case IndexInfo::isValidAttributeName($value):
                return self::T_ATTRIBUTE_NAME;

            case $value === '(':
                return self::T_OPEN_PARENTHESIS;

            case $value === ')':
                return self::T_CLOSE_PARENTHESIS;

            case $value === '=':
                return self::T_EQUALS;

            case $value === '>':
                return self::T_GREATER_THAN;

            case $value === '<':
                return self::T_LOWER_THAN;

            case $value === '!':
                return self::T_NEGATE;
        }

        return self::T_NONE;
    }
}
