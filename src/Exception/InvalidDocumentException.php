<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Exception;

class InvalidDocumentException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseDoesNotMatchSchema(array $schema, array $document, mixed $primaryKey = null): self
    {
        if ($primaryKey !== null) {
            return new self(
                sprintf(
                    'Document ID "%s" ("%s") does not match schema: %s',
                    $primaryKey,
                    json_encode($document),
                    json_encode($schema)
                )
            );
        }

        return new self(
            sprintf('Document ("%s") does not match schema: %s', json_encode($document), json_encode($schema))
        );
    }

    public static function becauseGeoAttributeHasWrongValueFormat(string $attributeName)
    {
        return new self(
            sprintf(
                'The "%s" attribute must have two keys only, which have to be named "lat" and "lng".',
                $attributeName
            )
        );
    }
}
