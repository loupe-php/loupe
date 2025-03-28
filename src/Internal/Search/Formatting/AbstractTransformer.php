<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Formatting;

interface AbstractTransformer
{
    /**
     * Transform the text and matches according to the transformer's rules.
     * Each transformer may modify both the text and the matches array.
     * The matches array should be adjusted if the text is modified in a way that affects match positions.
     *
     * @param array{starts: array<int>, ends: array<int>} $spans
     * @return array{0: string, 1: array{starts: array<int>, ends: array<int>}}
     */
    public function transform(string $text, array $spans): array;
}
