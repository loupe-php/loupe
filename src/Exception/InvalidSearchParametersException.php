<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

use Loupe\Loupe\SearchParameters;

class InvalidSearchParametersException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function maxLimit(): self
    {
        return new self(sprintf('Cannot request more than %d documents per request, use pagination.', SearchParameters::MAX_LIMIT));
    }
}
