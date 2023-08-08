<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

enum Direction: string
{
    case ASC = 'asc';
    case DESC = 'desc';

    public function getSQL(): string
    {
        return strtoupper($this->value);
    }
}
