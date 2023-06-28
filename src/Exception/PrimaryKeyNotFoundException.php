<?php

declare(strict_types=1);

namespace Loupe\Loupe\Exception;

class PrimaryKeyNotFoundException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseDoesNotExist(string $primaryKey): self
    {
        return new self(sprintf(
            'The primary key was configured to "%s" which does not exist on your document.',
            $primaryKey
        ));
    }
}
