<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

interface AttributeFilterInterface
{
    public function getAttribute(): string;

    public function getShortHash(): string;
}
