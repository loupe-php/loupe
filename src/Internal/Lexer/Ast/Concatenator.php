<?php

namespace Terminal42\Loupe\Internal\Lexer\Ast;

class Concatenator extends Node
{
    public function __construct(private string $concatenator)
    {}

    public function getConcatenator(): string
    {
        return $this->concatenator;
    }

    public function toArray(): array
    {
        return [
            $this->getConcatenator()
        ];
    }

    public static function fromString(string $concatenator): self
    {
        return new self(match ($concatenator) {
            'AND', '&&' => 'AND',
            'OR', '||' => 'OR',
            default => throw new \InvalidArgumentException('Invalid concatenator.')
        });
    }
}