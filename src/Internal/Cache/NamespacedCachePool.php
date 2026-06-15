<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class NamespacedCachePool implements CacheItemPoolInterface
{
    private string $namespace;

    public function __construct(
        private CacheItemPoolInterface $inner,
        string $namespace,
    ) {
        $this->namespace = rawurlencode($namespace);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($this->mapKey($key));
    }

    public function deleteItems(array $keys): bool
    {
        $mapped = [];
        foreach ($keys as $key) {
            $mapped[] = $this->mapKey((string) $key);
        }

        return $this->inner->deleteItems($mapped);
    }

    public function getItem(string $key): CacheItemInterface
    {
        return new NamespacedCacheItem($key, $this->inner->getItem($this->mapKey($key)));
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            $key = (string) $key;
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($this->mapKey($key));
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($item instanceof NamespacedCacheItem) {
            return $this->inner->save($item->unwrap());
        }

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($item instanceof NamespacedCacheItem) {
            return $this->inner->saveDeferred($item->unwrap());
        }

        return $this->inner->saveDeferred($item);
    }

    private function mapKey(string $key): string
    {
        return $this->namespace . '.' . $key;
    }
}
