<?php

declare(strict_types=1);

namespace Loupe\Loupe\Traits;

trait MemoizationTrait
{
    /**
     * @var array<string, mixed>
     */
    private array $memo = [];

    private function memoize(callable $compute): callable
    {
        $namespace = crc32(serialize($compute));

        return function(...$args) use ($namespace, $compute) {
            $key = crc32($namespace . ':' . serialize($args));
            if (!array_key_exists($key, $this->memo)) {
                $this->memo[$key] = call_user_func($compute, ...$args);
            }
            return $this->memo[$key];
        };
    }
}
