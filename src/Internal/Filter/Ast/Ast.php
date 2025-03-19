<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class Ast
{
    /**
     * @var array<int, int>
     */
    private array $ids = [];

    /**
     * @var array<Node>
     */
    private array $nodes = [];

    public function addNode(Node $node): self
    {
        $this->nodes[] = $node;

        return $this;
    }

    public function getIdForNode(Node $node): int
    {
        $objectId = spl_object_id($node);

        if (!isset($this->ids[$objectId])) {
            $this->ids[$objectId] = \count($this->ids);
        }

        return $this->ids[$objectId];
    }

    public function getRoot(): Group
    {
        // Do not unnecessarily nest groups if the root node is already a group
        if (\count($this->nodes) === 1 && $this->nodes[0] instanceof Group) {
            return $this->nodes[0];
        }

        return new Group($this->nodes);
    }

    /**
     * @return array<array<mixed>>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->nodes as $node) {
            $result[] = $node->toArray();
        }

        return $result;
    }
}
