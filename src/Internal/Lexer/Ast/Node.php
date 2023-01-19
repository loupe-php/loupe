<?php

namespace Terminal42\Loupe\Internal\Lexer\Ast;

abstract class Node
{

    abstract public function toArray(): array;
}