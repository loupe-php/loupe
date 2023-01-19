<?php

namespace Terminal42\Loupe\Internal\Filter\Ast;

class Group extends Node
{
    /**
     * @var array<Node>
     */
    private array $children = [];

    public function addChild(Node $node): self
    {
        $this->children[] = $node;

        return $this;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function toArray(): array
    {
        $return = [];

        foreach ($this->getChildren() as $child) {
            $return[] = $child->toArray();
        }

        return $return;
    }
}