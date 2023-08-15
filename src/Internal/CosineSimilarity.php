<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

class CosineSimilarity
{
    /**
     * @var array<string, array<float>>
     */
    private static array $queryTfIdfsCache = [];

    public static function fromQuery(string $queryId, string $queryIdfs, string $documentTfIdfs): float
    {
        // First we have to turn the query term IDFs into TF-IDF (we only have to do this once per query, so we can cache that)
        if (!isset(self::$queryTfIdfsCache[$queryId])) {
            $queryIdfs = array_map('floatval', explode(',', $queryIdfs));
            $tf = 1 / \count($queryIdfs);
            self::$queryTfIdfsCache[$queryId] = array_map(function (float $idf) use ($tf) {
                return $tf * $idf;
            }, $queryIdfs);
        }

        return self::similarity(self::$queryTfIdfsCache[$queryId], array_map('floatval', explode(',', $documentTfIdfs)));
    }

    /**
     * Cosine Similarity (d1, d2) =  Dot product(d1, d2) / ||d1|| * ||d2||
     *
     * ===
     *
     * Dot product (d1,d2) = d1[0] * d2[0] + d1[1] * d2[1] * … * d1[n] * d2[n]
     * ||d1|| = sqrt(d1[0]2 + d1[1]2 + ... + d1[n]2)
     * ||d2|| = sqrt(d2[0]2 + d2[1]2 + ... + d2[n]2)
     *
     *
     * Where d1 = query document (query terms)
     * Where d2 = resulting document (matching terms)
     *
     * @param array<float> $d1
     * @param array<float> $d2
     */
    public static function similarity(array $d1, array $d2): float
    {
        $prod = 0;
        $d1Norm = 0;
        $d2Norm = 0;

        foreach ($d1 as $i => $xi) {
            if (isset($d2[$i])) {
                $prod += $xi * $d2[$i];
            }
            $d1Norm += $xi * $xi;
        }
        $d1Norm = sqrt($d1Norm);

        foreach ($d2 as $xi) {
            $d2Norm += $xi * $xi;
        }

        $d2Norm = sqrt($d2Norm);

        return $prod / ($d1Norm * $d2Norm);
    }
}
