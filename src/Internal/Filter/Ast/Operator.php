<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Filter\Ast;

enum Operator: string
{
    case Equals = '=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case LowerThan = '<';
    case LowerThanOrEquals = '<=';

    public static function fromString(string $operator): self
    {
        return match ($operator) {
            '=' => self::Equals,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEquals,
            '<' => self::LowerThan,
            '<=' => self::LowerThanOrEquals,
            default => throw new \InvalidArgumentException('Invalid operator given.')
        };
    }
}
