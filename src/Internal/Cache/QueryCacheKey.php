<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Cache;

final class QueryCacheKey
{
    /**
     * 60 seconds matches interactive search/typeahead usage.
     */
    public const INTERACTIVE_TTL = 60;

    /**
     * @param list<bool|int|string> $parts
     */
    public static function build(string $prefix, string|int $version, array $parts = []): string
    {
        return self::join([
            $prefix,
            $version,
            ...$parts,
        ]);
    }

    /**
     * @param list<bool|int|string> $parts
     */
    private static function join(array $parts): string
    {
        $normalized = array_map(
            static fn (bool|int|string $part): string => rawurlencode((string) $part),
            $parts
        );

        return implode('.', $normalized);
    }
}
