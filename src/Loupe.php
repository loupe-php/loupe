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
    public function addDocument(array $document): void
    {
        $this->addDocuments([$document]);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): void
    {
        $this->engine->addDocuments($documents);
    }

    public function browse(BrowseParameters $parameters): BrowseResult
    {
        return $this->engine->browse($parameters);
    }

    public function countDocuments(): int
    {
        return $this->engine->countDocuments();
    }

    public function deleteAllDocuments(): void
    {
        $this->engine->deleteAllDocuments();
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

    public function size(): int
    {
        return $this->engine->size();
    }
}
