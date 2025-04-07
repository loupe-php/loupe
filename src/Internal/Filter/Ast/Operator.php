<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Doctrine\DBAL\Connection;

enum Operator: string
{
    case Between = 'BETWEEN';
    case Equals = '=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case In = 'IN';
    case LowerThan = '<';
    case LowerThanOrEquals = '<=';
    case NotBetween = 'NOT BETWEEN';
    case NotEquals = '!=';
    case NotIn = 'NOT IN';

    public function buildSql(Connection $connection, string $attribute, FilterValue $value): string
    {
        $value = $value->getValue();

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

            if ($this === self::Between || $this === self::NotBetween) {
                return $attribute . ' ' . $this->value . ' ' . $value[0] . ' AND ' . $value[1];
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
                self::NotEquals->buildSql($connection, $attribute, FilterValue::createNull()) .
                ')',
            self::Between,
            self::NotBetween,
            self::In,
            self::NotIn => throw new \InvalidArgumentException('Can only use IN(), NOT IN(), BETWEEN and NOT BETWEEN with arrays.')
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
            'BETWEEN' => self::Between,
            'NOT BETWEEN' => self::NotBetween,
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
            self::Between,
            self::In => false,
            self::NotIn,
            self::NotBetween,
            self::NotEquals => true,
        };
    }

    public function isPositive(): bool
    {
        return !$this->isNegative();
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
            self::Between => self::NotBetween,
            self::NotBetween => self::Between,
        };
    }

    private function quote(Connection $connection, float|string|bool|null &$value): void
    {
        if (\is_string($value)) {
            $value = $connection->quote($value);
        }
    }
}
