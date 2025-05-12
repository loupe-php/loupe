<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Internal\Search\AbstractQueryResult;

final class BrowseResult extends AbstractQueryResult
{
    public static function createEmptyFromBrowseParameters(BrowseParameters $browseParameters): self
    {
        return new self(
            [],
            $browseParameters->getQuery(),
            0,
            $browseParameters->getHitsPerPage() ?? $browseParameters->getLimit(),
            1,
            0,
            0
        );
    }
}
