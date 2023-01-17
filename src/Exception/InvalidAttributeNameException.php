<?php

namespace Terminal42\Loupe\Exception;

class InvalidAttributeNameException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    public static function becauseFormatDoesNotMatch(string $attributeName): self
    {
        return new self(
            sprintf('A valid attribute name starts with a letter or underscore, followed by any number of letters, numbers, or underscores. It must not exceed %d characters. "%s" given.',
            self::MAX_ATTRIBUTE_NAME_LENGTH,
                $attributeName
        ));
    }
}