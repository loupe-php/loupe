<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

class MultiAttributeFilter extends AbstractGroup
{
    /**
     * @param array<Node> $children
     */
    public function __construct(
        public string $attribute,
        array $children = []
    ) {
        parent::__construct($children);
    }

    public function toArray(): array
    {
        $return = [
            'attribute' => $this->attribute,
            'children' => [],
        ];

        foreach ($this->getChildren() as $child) {
            $return['children'][] = $child->toArray();
        }

        return $return;
    }
}
