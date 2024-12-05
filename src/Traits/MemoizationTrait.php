<?php

namespace Loupe\Loupe\Traits;

trait MemoizationTrait
{
    private function memoize(callable $compute): callable
    {
        return function () use ($compute) {
            /**
             * @var array<string, mixed>
             */
            static $cache = [];
            $args = \func_get_args();
            $key = implode('--', $args);
            if (!\array_key_exists($key, $cache)) {
                $cache[$key] = \call_user_func_array($compute, $args);
            }
            return $cache[$key];
        };
    }
}
