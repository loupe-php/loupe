<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Terminal42\Loupe\Exception\IndexException;
use Terminal42\Loupe\Internal\Configuration;
use Terminal42\Loupe\Internal\Engine;

final class Loupe
{
    public function __construct(
        private Engine $engine
    ) {
    }

    /**
     * @throws IndexException
     */
    public function addDocument(array $document): self
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws IndexException
     */
    public function addDocuments(array $documents): self
    {
        $this->engine->addDocuments($documents);

        return $this;
    }

    public function countDocuments(): int
    {
        return $this->engine->countDocuments();
    }

    public function getConfiguration(): Configuration
    {
        return $this->engine->getConfiguration();
    }

    public function getDocument(int|string $identifier): ?array
    {
        return $this->engine->getDocument($identifier);
    }

    public function search(SearchParameters $parameters): array
    {
        return $this->engine->search($parameters);
    }
}
