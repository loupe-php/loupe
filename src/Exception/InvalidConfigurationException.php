<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

use Loupe\Loupe\Configuration;

class InvalidConfigurationException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseCouldNotCreateDataDir(string $folder): self
    {
        return new self(
            sprintf(
                'Could not create data directory at "%s".',
                $folder
            )
        );
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
}
