<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

final class StaticCache
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private static array $cache = [];

    private static ?int $currentContextObjectId = null;

    /**
     * @var array<int, \WeakReference>
     */
    private static array $referenceMap = [];

    public static function cleanUp(?object $object = null): void
    {
        self::$currentContextObjectId = null;

        foreach (self::$referenceMap as $objectId => $reference) {
            if ($reference->get() === null || ($object !== null && $objectId === spl_object_id($object))) {
                unset(self::$cache[$objectId]);
                unset(self::$referenceMap[$objectId]);
            }
        }
    }

    public static function enterContext(object $object): void
    {
        self::$currentContextObjectId = spl_object_id($object);
        self::$referenceMap[self::$currentContextObjectId] = \WeakReference::create($object);
    }

    public static function get(string $key): mixed
    {
        self::ensureCurrentContext();

        if (self::has($key)) {
            return self::$cache[self::$currentContextObjectId][$key];
        }

        return null;
    }

    public static function has(string $key): bool
    {
        self::ensureCurrentContext();

        return isset(self::$cache[self::$currentContextObjectId]) &&
            self::$referenceMap[self::$currentContextObjectId]->get() !== null &&
            \array_key_exists($key, self::$cache[self::$currentContextObjectId]);
    }

    public static function isEmpty(): bool
    {
        return self::$cache === [];
    }

    public static function set(string $key, mixed $value): mixed
    {
        self::$cache[self::ensureCurrentContext()][$key] = $value;

        return $value;
    }

    private static function ensureCurrentContext(): int
    {
        if (self::$currentContextObjectId === null) {
            throw new \LogicException('Must enter static cache context first.');
        }

        return self::$currentContextObjectId;
    }
}
