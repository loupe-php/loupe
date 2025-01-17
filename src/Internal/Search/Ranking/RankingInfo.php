<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

final class RankingInfo
{
    /**
     * @param array<string> $rankingRules
     * @param array<string> $searchableAttributes
     */
    private function __construct(
        private array $rankingRules,
        private array $searchableAttributes,
        private TermPositions $termPositions
    ) {
    }

    /**
     * Example: A string with "3:title,8:title,10:title;0;4:summary" would read as follows:
     * - The query consisted of 3 tokens (terms).
     * - The first term matched. At positions 3, 8 and 10 in the `title` attribute.
     * - The second term did not match (position 0).
     * - The third term matched. At position 4 in the `summary` attribute.
     *
     * @param string $termPositions A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQueryFunction(string $searchableAttributes, string $rankingRules, string $termPositions): self
    {
        return new self(explode(':', $rankingRules), explode(':', $searchableAttributes), TermPositions::fromQueryFunction($termPositions));
    }

    /**
     * Returns the ranking rules in order as defined in Configuration.
     *
     * @return array<string>
     */
    public function getRankingRules(): array
    {
        return $this->rankingRules;
    }

    /**
     * Returns the searchable attributes as defined in Configuration.
     *
     * @return array<string>
     */
    public function getSearchableAttributes(): array
    {
        return $this->searchableAttributes;
    }

    public function getTermPositions(): TermPositions
    {
        return $this->termPositions;
    }
}
