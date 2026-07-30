<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

use Loupe\Loupe\Configuration;

class InvalidConfigurationException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseCouldNotCreateDataDir(string $folder): self
    {
        return new self(
            \sprintf(
                'Could not create data directory at "%s".',
                $folder,
            ),
        );
    }

    public static function becauseInvalidAttributeName(string $attributeName): self
    {
        return new self(
            \sprintf(
                'A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed %d characters. "%s" given.',
                Configuration::MAX_ATTRIBUTE_NAME_LENGTH,
                $attributeName,
            ),
        );
    }

    public static function becauseInvalidSynonymKey(string $key): self
    {
        return new self(
            \sprintf(
                'A valid synonym key is a non-empty single-word string. "%s" given.',
                $key,
            ),
        );
    }

    public static function becauseInvalidSynonymValue(string $value): self
    {
        return new self(
            \sprintf(
                'A valid synonym value is an array of non-empty single-word strings. "%s" given.',
                $value,
            ),
        );
    }

    public static function becauseRequiredDataDirMissing(): self
    {
        return new self('Data directory argument is required and cannot be empty.');
    }
}
