<?php

namespace Terminal42\Loupe\Internal\Filter\Ast;

class Filter extends Node
{
    public function __construct(public string $attribute, public Operator $operator, public float|string $value)
    {}

    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}