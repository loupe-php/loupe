<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Doctrine\DBAL\Connection;
use Loupe\Loupe\Internal\LoupeTypes;

enum Operator: string
{
    case Equals = '=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case In = 'IN';
    case LowerThan = '<';
    case LowerThanOrEquals = '<=';
    case NotEquals = '!=';
    case NotIn = 'NOT IN';

    /**
     * @param float|string|bool|array<mixed> $value
     */
    public function buildSql(Connection $connection, string $attribute, float|string|bool|array $value): string
    {
        if (\is_array($value)) {
            foreach ($value as &$v) {
                $this->quote($connection, $v);
            }
        } else {
            $this->quote($connection, $value);
        }

        if (\is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if (\is_array($value)) {
            if ($this === self::In || $this === self::NotIn) {
                return $attribute . ' ' . $this->value . ' (' . implode(', ', $value) . ')';
            }

            throw new \InvalidArgumentException('Can oly work with arrays for IN() and NOT IN().');
        }

        return match ($this) {
            self::Equals,
            self::NotEquals => $attribute . ' ' . $this->value . ' ' . $value,
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LowerThan,
            self::LowerThanOrEquals => '(' .
                $attribute . ' ' .
                $this->value . ' ' .
                $value .
                ' AND ' .
                self::NotEquals->buildSql($connection, $attribute, LoupeTypes::VALUE_NULL) .
                ')',
            self::In, self::NotIn => throw new \InvalidArgumentException('Can only use IN() and NOT IN() with arrays.')
        };
    }

    public static function fromString(string $operator): self
    {
        return match ($operator) {
            '=' => self::Equals,
            '!=' => self::NotEquals,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEquals,
            '<' => self::LowerThan,
            '<=' => self::LowerThanOrEquals,
            'IN' => self::In,
            'NOT IN' => self::NotIn,
            default => throw new \InvalidArgumentException('Invalid operator given.')
        };
    }

    public function isNegative(): bool
    {
        return match ($this) {
            self::Equals,
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LowerThan,
            self::LowerThanOrEquals,
            self::In => false,
            self::NotIn,
            self::NotEquals => true,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Equals => self::NotEquals,
            self::NotEquals => self::Equals,
            self::GreaterThan => self::LowerThanOrEquals,
            self::GreaterThanOrEquals => self::LowerThan,
            self::LowerThan => self::GreaterThanOrEquals,
            self::LowerThanOrEquals => self::GreaterThan,
            self::In => self::NotIn,
            self::NotIn => self::In,
        };
    }

    private function quote(Connection $connection, float|string|bool|null &$value): void
    {
        if (\is_string($value)) {
            $value = $connection->quote($value);
        }
    }
}
