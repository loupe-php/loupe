<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Filter extends Node
{
    /**
     * @param float|string|array<mixed> $value
     */
    public function __construct(
        public string $attribute,
        public Operator $operator,
        public float|string|array $value
    ) {
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}
