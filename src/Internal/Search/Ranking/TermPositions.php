<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

use Loupe\Loupe\Internal\Search\Ranking\TermPositions\Position;
use Loupe\Loupe\Internal\Search\Ranking\TermPositions\Term;
use Loupe\Loupe\Internal\Search\Ranking\TermPositions\TermMatch;

class TermPositions
{
    /**
     * @var array<string, bool>
     */
    private array $matchingAttributes = [];

    private int $totalExactMatchingTerms = 0;

    private int $totalMatchingTerms = 0;

    private int $totalNumberOfTypos = 0;

    private int $totalTermsSearchedFor;

    /**
     * @param array<Term> $terms
     */
    public function __construct(
        private readonly array $terms
    ) {
        $this->totalTermsSearchedFor = \count($this->terms);

        foreach ($this->terms as $term) {
            if ($term->hasMatches()) {
                $this->totalMatchingTerms++;

                $this->totalNumberOfTypos += $totalNumberOfTypos = $term->getLowestNumberOfTypos();

                if ($totalNumberOfTypos === 0) {
                    $this->totalExactMatchingTerms++;
                }

                foreach ($term->getMatches() as $match) {
                    $this->matchingAttributes[$match->getAttribute()] = true;
                }
            }
        }
    }

    /**
     * Parse an intermediate string representation of term positions and matches attributes
     *
     * Example: A string with "3:title,8:title,10:title;0;4:summary" would read as follows:
     * * - The query consisted of 3 tokens (terms).
     * * - The first term matched. At positions 3, 8 and 10 in the `title` attribute.
     * * - The second term did not match (position 0).
     * * - The third term matched. At position 4 in the `summary` attribute.
     * *
     * * @param string $positionsInDocumentPerTerm A string of ";" separated per term and "," separated for all the term positions within a document
     */
    public static function fromQueryFunction(string $positionsInDocumentPerTerm): self
    {
        $terms = [];

        if ($positionsInDocumentPerTerm === '') {
            return new self($terms);
        }

        foreach (explode(';', $positionsInDocumentPerTerm) as $termSearchedFor) {
            // Document did not match this term
            if ($termSearchedFor === '0') {
                $terms[] = new Term([]);
                continue;
            }

            $attributePositions = [];
            $termMatches = [];

            foreach (explode(',', $termSearchedFor) as $positionAttributeCombination) {
                [$position, $attribute, $numberOfTypos] = explode(':', $positionAttributeCombination, 3);
                $attributePositions[$attribute][] = new Position((int) $position, (int) $numberOfTypos);
            }

            foreach ($attributePositions as $attribute => $positions) {
                $termMatches[] = new TermMatch($attribute, $positions);
            }

            $terms[] = new Term($termMatches);
        }

        return new self($terms);
    }

    /**
     * @return array<string>
     */
    public function getMatchingAttributes(): array
    {
        return array_keys($this->matchingAttributes);
    }

    /**
     * @return array<Term>
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    public function getTotalExactMatchingTerms(): int
    {
        return $this->totalExactMatchingTerms;
    }

    public function getTotalMatchingTerms(): int
    {
        return $this->totalMatchingTerms;
    }

    public function getTotalNumberOfTypos(): int
    {
        return $this->totalNumberOfTypos;
    }

    public function getTotalTermsSearchedFor(): int
    {
        return $this->totalTermsSearchedFor;
    }
}
