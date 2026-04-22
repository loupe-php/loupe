<?php

declare(strict_types=1);

namespace Loupe\Loupe\Config;

enum MatchingStrategy: string
{
    case All = 'all';
    case Any = 'any';
}
