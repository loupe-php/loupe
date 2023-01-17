<?php

namespace Terminal42\Loupe\Internal;

class Levenshtein
{
    public static function levenshtein(string $string1, string $string2): int
    {
        return levenshtein($string1, $string2);
    }

    public static function maxLevenshtein(string $string1, string $string2, int $max): bool
    {
        // Maybe this can be optimized in the future. We're looking if $string1's distance to $string2 is
        // more or equal to $max. This means we do not care about the actual distance, just the boolean result.
        // So we don't have to e.g. count to a distance of 10 if $max is 2, we can early return.
        // Not sure if worth it, though as implementing it in PHP would be slower than in C so it might end up having
        // the same performance.
        return levenshtein($string1, $string2) >= $max;
    }
}