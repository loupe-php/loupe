<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

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

    /**
     * @return array<Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function isEmpty(): bool
    {
        return $this->getChildren() === [];
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
