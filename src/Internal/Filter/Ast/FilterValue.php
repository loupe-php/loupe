<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Loupe\Loupe\Internal\LoupeTypes;

class FilterValue
{
    /**
     * @param float|string|bool|array<int, string|float|bool> $value
     */
    public function __construct(
        private float|string|bool|array $value
    ) {
        if (\is_array($this->value)) {
            foreach ($this->value as $value) {
                if (!\is_string($value) && !\is_float($value) && !\is_bool($value)) {
                    throw new \InvalidArgumentException('Array values must be either of type string, float or boolean');
                }
            }

            sort($this->value); // For hashing
        }
    }

    public static function createEmpty(): self
    {
        return new self(LoupeTypes::VALUE_EMPTY);
    }

    public static function createNull(): self
    {
        return new self(LoupeTypes::VALUE_NULL);
    }

    public function getMultiAttributeColumn(): string
    {
        return LoupeTypes::isFloatType(LoupeTypes::getTypeFromValue($this->value)) ? 'numeric_value' : 'string_value';
    }

    /**
     * @return float|string|bool|array<int, string|float|bool>
     */
    public function getValue(): float|string|bool|array
    {
        return $this->value;
    }
}
