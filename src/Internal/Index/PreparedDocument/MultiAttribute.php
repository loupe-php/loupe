<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\PreparedDocument;

class MultiAttribute
{
    /**
     * @param array<string|float> $values
     */
    public function __construct(
        private string $name,
        private array $values
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return float[]|string[]
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
