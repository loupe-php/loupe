<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

final class ApcuCacheItem implements CacheItemInterface
{
    private bool $hit;

    private ?int $ttl = null;

    private mixed $value;

    public function __construct(
        private string $key,
        mixed $value = null,
        bool $hit = false
    ) {
        $this->value = $value;
        $this->hit = $hit;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->ttl = null;
            return $this;
        }

        if ($time instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $this->ttl = max(0, $now->add($time)->getTimestamp() - $now->getTimestamp());
            return $this;
        }

        $this->ttl = max(0, $time);

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->ttl = null;
            return $this;
        }

        $ttl = $expiration->getTimestamp() - time();
        $this->ttl = max(0, $ttl);

        return $this;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }
}
