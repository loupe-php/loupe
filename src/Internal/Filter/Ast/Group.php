<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Group extends Node
{
    /**
     * @var array<string, bool>
     */
    private array $attributes = [];

    /**
     * @var array<Node>
     */
    private array $children = [];

    private bool $isConjunctive = true;

    /**
     * @param array<Node> $children
     */
    public function __construct(array $children = [])
    {
        foreach ($children as $child) {
            $this->addChild($child);
        }
    }

    public function addChild(Node $node): self
    {
        $this->children[] = $node;

        if ($node instanceof Concatenator && !$node->isConjunctive()) {
            $this->isConjunctive = false;
        }

        if ($node instanceof AttributeFilterInterface) {
            $this->attributes[$node->getAttribute()] = true;
        }

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getAttributes(): array
    {
        return array_keys($this->attributes);
    }

    /**
     * @return array<Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function isConjunctive(): bool
    {
        return $this->isConjunctive;
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
