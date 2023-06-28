<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

class InvalidSearchParametersException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function cannotHighlightBecauseNotSearchable(string $attributeName): self
    {
        return new self(sprintf('Cannot highlight "%s" because it is not searchable.', $attributeName));
    }
}
