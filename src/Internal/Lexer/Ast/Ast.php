<?php

namespace Terminal42\Loupe\Internal\Lexer\Ast;

class Ast
{
    private array $nodes = [];

    public function addNode(Node $node): self
    {
        $this->nodes[] = $node;

        return $this;
    }

    /**
     * @return array<Node>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function toArray(): array
    {
        $result = [];

        foreach ($this->nodes as $node) {
            $result[] = $node->toArray();
        }

        return $result;
    }
}