<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\PreparedDocument;

class SingleAttribute
{
    public function __construct(
        private string $name,
        private string|float|bool $value
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string|float|bool
    {
        return $this->value;
    }
}
