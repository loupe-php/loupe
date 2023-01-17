<?php

namespace Terminal42\Loupe\Exception;

class InvalidConfigurationException extends \InvalidArgumentException implements LoupeExceptionInterface
{
    public static function becauseIndexConfigurationMissing(string $index): self
    {
        return new self(sprintf('There is no configuration for index "%s".',
            $index
        ));
    }
}