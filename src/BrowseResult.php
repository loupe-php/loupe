<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Internal\Search\AbstractQueryResult;

final class BrowseResult extends AbstractQueryResult
{
    public static function createEmptyFromBrowseParameters(BrowseParameters $parameters): self
    {
        return new self(
            [],
            $parameters->getQuery(),
            0,
            $parameters->getHitsPerPage(),
            $parameters->getPage(),
            0,
            0
        );
    }
}
