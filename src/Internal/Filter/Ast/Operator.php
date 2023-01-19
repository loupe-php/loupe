<?php

namespace Terminal42\Loupe\Internal\Filter\Ast;

enum Operator: string
{
    case Equals = '=';
    case GreaterThan = '>';
    case LowerThan = '<';

    public static function fromString(string $operator): self
    {
        return match ($operator) {
            '=' => self::Equals,
            '>' => self::GreaterThan,
            '<' => self::LowerThan,
            default => throw new \InvalidArgumentException('Invalid operator given.')
        };
    }
}