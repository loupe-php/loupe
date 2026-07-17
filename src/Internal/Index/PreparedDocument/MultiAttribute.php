<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\PreparedDocument;

class MultiAttribute
{
    /**
     * @param array<string|float|bool> $values
     */
    public function __construct(
        private readonly string $name,
        private readonly array $values,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<float>|array<string>|array<bool>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
