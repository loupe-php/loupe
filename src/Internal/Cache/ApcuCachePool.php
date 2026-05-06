<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ApcuCachePool implements CacheItemPoolInterface
{
    /**
     * @var array<string, ApcuCacheItem>
     */
    private array $deferred = [];

    public function clear(): bool
    {
        $this->deferred = [];
        return apcu_clear_cache();
    }

    public function commit(): bool
    {
        $ok = true;

        foreach ($this->deferred as $key => $item) {
            if (!$this->save($item)) {
                $ok = false;
            }
            unset($this->deferred[$key]);
        }

        return $ok;
    }

    public function deleteItem(string $key): bool
    {
        self::assertValidKey($key);
        unset($this->deferred[$key]);
        return (bool) apcu_delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->deleteItem((string) $key)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function getItem(string $key): CacheItemInterface
    {
        self::assertValidKey($key);

        if (isset($this->deferred[$key])) {
            return $this->deferred[$key];
        }

        $value = apcu_fetch($key, $success);
        if ($success) {
            return new ApcuCacheItem($key, $value, true);
        }

        return new ApcuCacheItem($key, null, false);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        self::assertValidKey($key);
        return isset($this->deferred[$key]) || apcu_exists($key);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
            throw new InvalidArgumentException('Unsupported cache item implementation.');
        }

        $ttl = $item->getTtl() ?? 0;
        return apcu_store($item->getKey(), $item->get(), $ttl);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
            throw new InvalidArgumentException('Unsupported cache item implementation.');
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    private static function assertValidKey(string $key): void
    {
        if ($key === '' || preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new InvalidArgumentException('Invalid cache key: ' . $key);
        }
    }
}
