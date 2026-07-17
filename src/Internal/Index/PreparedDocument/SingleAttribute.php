<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\PreparedDocument;

class SingleAttribute
{
    public function __construct(
        private string $name,
        private bool|float|string $value,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): bool|float|string
    {
        return $this->value;
    }
}
