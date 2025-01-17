<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Toflar\StateSetIndex\DamerauLevenshtein;

class Levenshtein
{
    public static function damerauLevenshtein(string $string1, string $string2, bool|int $firstCharTypoCountsDouble): int
    {
        $distance = DamerauLevenshtein::distance($string1, $string2);

        if ($firstCharTypoCountsDouble && mb_substr($string1, 0, 1) !== mb_substr($string2, 0, 1)) {
            $distance++;
        }

        return $distance;
    }

    public static function maxLevenshtein(string $string1, string $string2, int $maxDistance, int|bool $firstCharTypoCountsDouble): bool
    {
        $distance = DamerauLevenshtein::distance($string1, $string2, $maxDistance + 1);

        if ($firstCharTypoCountsDouble && mb_substr($string1, 0, 1) !== mb_substr($string2, 0, 1)) {
            ++$distance;
        }

        return $distance <= $maxDistance;
    }
}
