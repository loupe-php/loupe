<?php

namespace Terminal42\Loupe\Exception;

use Terminal42\Loupe\Internal\Index\IndexInfo;

class InvalidDocumentException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseInvalidAttributeName(string $attributeName): self
    {
        return new self(
            sprintf('A valid attribute name starts with a letter or underscore, followed by any number of letters, numbers, or underscores. It must not exceed %d characters. "%s" given.',
                IndexInfo::MAX_ATTRIBUTE_NAME_LENGTH,
                $attributeName
        ));
    }

    public static function becauseDoesNotMatchSchema(array $schema): self
    {
        return new self(
            sprintf('Document does not match schema: %s', json_encode($schema))
        );
    }

    public static function becauseAttributeNotSortable(string $attributeName): self
    {
        return new self(
            sprintf('Cannot sort on this type of attribute value for attribute "%s".', $attributeName)
        );
    }
}