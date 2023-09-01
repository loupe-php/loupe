<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Exception\IndexException;
use Loupe\Loupe\Internal\Engine;

final class Loupe
{
    public function __construct(
        private Engine $engine
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public function addDocument(array $document): IndexResult
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): IndexResult
    {
        return $this->engine->addDocuments($documents);
    }

    public function countDocuments(): int
    {
        return $this->engine->countDocuments();
    }

    /**
     * @throws IndexException
     */
    public function deleteDocument(int|string $id): void
    {
        $this->deleteDocuments([$id]);
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): void
    {
        $this->engine->deleteDocuments($ids);
    }

    public function getConfiguration(): Configuration
    {
        return $this->engine->getConfiguration();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDocument(int|string $identifier): ?array
    {
        return $this->engine->getDocument($identifier);
    }

    public function needsReindex(): bool
    {
        return $this->engine->needsReindex();
    }

    public function search(SearchParameters $parameters): SearchResult
    {
        return $this->engine->search($parameters);
    }
}
