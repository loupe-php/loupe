<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Filter extends Node
{
    public function __construct(
        public string $attribute,
        public Operator $operator,
        public FilterValue $value
    ) {
    }

    public function getShortHash(): string
    {
        return substr(hash('sha256', json_encode($this->toArray())), 0, 8);
    }

    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'operator' => $this->operator->value,
            'value' => $this->value->getValue(),
        ];
    }
}
