<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

class SortFormatException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseFormat(): self
    {
        return new self('Sort parameters must be in the following format: ["title:asc"].');
    }

    public static function becauseNotSortable(string $sort): self
    {
        return new self(sprintf('Cannot sort by "%s". It must be defined as sortable attribute.', $sort));
    }
}
