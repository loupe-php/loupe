<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Concatenator extends Node
{
    public function __construct(
        private string $concatenator
    ) {
    }

    public static function fromString(string $concatenator): self
    {
        return new self(match ($concatenator) {
            'AND', '&&' => 'AND',
            'OR', '||' => 'OR',
            default => throw new \InvalidArgumentException('Invalid concatenator.')
        });
    }

    public function getConcatenator(): string
    {
        return $this->concatenator;
    }

    public function getSetOperator(): string
    {
        return match ($this->concatenator) {
            'AND' => 'INTERSECT',
            'OR' => 'UNION',
            default => throw new \InvalidArgumentException('Invalid concatenator.')
        };
    }

    public function isConjunctive(): bool
    {
        return $this->concatenator === 'AND';
    }

    public function toArray(): array
    {
        return [$this->getConcatenator()];
    }
}
