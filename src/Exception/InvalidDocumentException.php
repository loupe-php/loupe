<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

class InvalidDocumentException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $document
     */
    public static function becauseDoesNotMatchSchema(array $schema, array $document, ?string $primaryKey = null): self
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
}
