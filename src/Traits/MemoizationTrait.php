<?php

declare(strict_types=1);

namespace Loupe\Loupe\Traits;

trait MemoizationTrait
{
    /**
     * @var array<mixed>
     */
    private array $memo = [];

    private function memoize(callable $compute): mixed
    {
        $namespace = serialize($compute);

        return function() use ($namespace, $compute) {
            $args = func_get_args();
            $key = md5($namespace . serialize($args));
            return $this->memo[$key] ??= call_user_func_array($compute, $args);
        };
    }
}
