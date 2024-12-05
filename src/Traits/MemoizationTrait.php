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
        $namespace = serialize($compute);

        return function() use ($namespace, $compute) {
            $args = func_get_args();
            $key = $namespace . ':' . implode(':', $args);
            if (!array_key_exists($key, $this->memo)) {
                $this->memo[$key] = call_user_func_array($compute, $args);
            }
            return $this->memo[$key];
        };
    }
}
