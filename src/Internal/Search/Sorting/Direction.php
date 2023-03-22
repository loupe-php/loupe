<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Sorting;

enum Direction: string
{
    case ASC = 'asc';
    case DESC = 'desc';

    public function getSQL()
    {
        return strtoupper($this->value);
    }
}
