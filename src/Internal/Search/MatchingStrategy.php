<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search;

enum MatchingStrategy: string
{
    case All = 'all';
    case Any = 'any';
}
