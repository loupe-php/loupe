<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

use Loupe\Loupe\Configuration;

class InvalidConfigurationException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseAttributeNotSortable(string $attributeName): self
    {
        return new self(sprintf('Cannot sort on this type of attribute value for attribute "%s".', $attributeName));
    }

    public static function becauseInvalidAttributeName(string $attributeName): self
    {
        return new self(
            sprintf(
                'A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed %d characters. "%s" given.',
                Configuration::MAX_ATTRIBUTE_NAME_LENGTH,
                $attributeName
            )
        );
    }

    public static function becauseInvalidDbPath(string $dbPath): self
    {
        return new self(
            sprintf(
                '"%s" does not exist, create an empty database file first.',
                $dbPath
            )
        );
    }
}
