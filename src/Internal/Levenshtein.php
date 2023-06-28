<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

class Levenshtein
{
    public static function levenshtein(string $string1, string $string2, bool $firstCharTypoCountsDouble): int
    {
        $distance = \Toflar\StateSetIndex\Levenshtein::distance($string1, $string2);

        if ($firstCharTypoCountsDouble && mb_substr($string1, 0, 1) !== mb_substr($string2, 0, 1)) {
            $distance++;
        }

        return $distance;
    }

    public static function maxLevenshtein(string $string1, string $string2, int $max, bool $firstCharTypoCountsDouble): bool
    {
        // Maybe this can be optimized in the future. We're looking if $string1's distance to $string2 is
        // more or equal to $max. This means we do not care about the actual distance, just the boolean result.
        // So we don't have to e.g. count to a distance of 10 if $max is 2, we can early return.
        // Not sure if worth it, though as implementing it in PHP would be slower than in C so it might end up having
        // the same performance.
        return self::levenshtein($string1, $string2, $firstCharTypoCountsDouble) <= $max;
    }
}
