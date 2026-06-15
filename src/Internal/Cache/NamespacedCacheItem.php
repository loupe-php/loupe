<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

final class NamespacedCacheItem implements CacheItemInterface
{
    public function __construct(
        private string $key,
        private CacheItemInterface $inner,
    ) {
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->inner->expiresAfter($time);

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->inner->expiresAt($expiration);

        return $this;
    }

    public function get(): mixed
    {
        return $this->inner->get();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isHit(): bool
    {
        return $this->inner->isHit();
    }

    public function set(mixed $value): static
    {
        $this->inner->set($value);

        return $this;
    }

    public function unwrap(): CacheItemInterface
    {
        return $this->inner;
    }
}
