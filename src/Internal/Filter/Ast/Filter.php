<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Filter extends Node
{
    /**
     * @param float|string|array<mixed>|null $value
     */
    public function __construct(
        public string $attribute,
        public Operator $operator,
        public float|string|array|null $value = null
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
