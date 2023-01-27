<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Internal\Configuration;
use Terminal42\Loupe\Internal\Engine;

final class Loupe
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function addDocument(array $document): self
    {
        $this->engine->addDocument($document);

        return $this;
    }

    public function getConfiguration(): Configuration
    {
        return $this->engine->getConfiguration();
    }

    public function getDocument(int|string $identifier): ?array
    {
        return $this->engine->getDocument($identifier);
    }

    public function search(array $parameters): array
    {
        return $this->engine->search($parameters);
    }
}
