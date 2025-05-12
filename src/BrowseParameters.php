<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Internal\Search\AbstractQueryParameters;

final class BrowseParameters extends AbstractQueryParameters
{
    public static function create(): static
    {
        return new self();
    }
}
