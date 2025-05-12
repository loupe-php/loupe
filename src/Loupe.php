<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Exception\IndexException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\StaticCache;

final class Loupe
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function __destruct()
    {
        StaticCache::cleanUp($this);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function addDocument(array $document): IndexResult
    {
        StaticCache::enterContext($this);
        return $this->addDocuments([$document]);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): IndexResult
    {
        StaticCache::enterContext($this);
        return $this->engine->addDocuments($documents);
    }

    public function browse(BrowseParameters $parameters): BrowseResult
    {
        StaticCache::enterContext($this);
        return $this->engine->browse($parameters);
    }

    public function countDocuments(): int
    {
        StaticCache::enterContext($this);
        return $this->engine->countDocuments();
    }

    public function deleteAllDocuments(): void
    {
        StaticCache::enterContext($this);
        $this->engine->deleteAllDocuments();
    }

    /**
     * @throws IndexException
     */
    public function deleteDocument(int|string $id): void
    {
        StaticCache::enterContext($this);
        $this->deleteDocuments([$id]);
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): void
    {
        StaticCache::enterContext($this);
        $this->engine->deleteDocuments($ids);
    }

    public function getConfiguration(): Configuration
    {
        StaticCache::enterContext($this);
        return $this->engine->getConfiguration();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDocument(int|string $identifier): ?array
    {
        StaticCache::enterContext($this);
        return $this->engine->getDocument($identifier);
    }

    public function needsReindex(): bool
    {
        StaticCache::enterContext($this);
        return $this->engine->needsReindex();
    }

    public function search(SearchParameters $parameters): SearchResult
    {
        StaticCache::enterContext($this);
        return $this->engine->search($parameters);
    }

    public function size(): int
    {
        StaticCache::enterContext($this);
        return $this->engine->size();
    }
}
