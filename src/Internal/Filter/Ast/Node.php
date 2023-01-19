<?php

namespace Terminal42\Loupe\Internal\Filter\Ast;

abstract class Node
{

    abstract public function toArray(): array;
}