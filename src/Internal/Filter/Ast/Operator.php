<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Doctrine\DBAL\ParameterType;

enum Operator: string
{
    case Equals = '=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case In = 'IN';
    case LowerThan = '<';
    case LowerThanOrEquals = '<=';
    case NotIn = 'NOT IN';

    public function buildSql(\Doctrine\DBAL\Connection $connection, float|string|array $value): string
    {
        if (\is_array($value)) {
            foreach ($value as &$v) {
                $this->quote($connection, $v);
            }
        } else {
            $this->quote($connection, $value);
        }

        return match ($this) {
            self::Equals,
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LowerThan,
            self::LowerThanOrEquals => $this->value . ' ' . $value,
            self::In, self::NotIn => $this->value . ' (' . implode(', ', $value) . ')',
        };
    }

    public static function fromString(string $operator): self
    {
        return match ($operator) {
            '=' => self::Equals,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEquals,
            '<' => self::LowerThan,
            '<=' => self::LowerThanOrEquals,
            'IN' => self::In,
            'NOT IN' => self::NotIn,
            default => throw new \InvalidArgumentException('Invalid operator given.')
        };
    }

    public function getMultiValueOperator(): self
    {
        return match ($this) {
            // negatives need to be inverted for the multi value check so they are correctly filtered out
            self::NotIn => self::In,
            default => $this,
        };
    }

    private function quote($connection, float|string &$value): void
    {
        if (\is_string($value)) {
            $value = $connection->quote($value, ParameterType::STRING);
        }
    }
}
