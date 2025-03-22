<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class Proximity extends AbstractRanker
{
    public static function calculate(RankingInfo $rankingInfo): float
    {
        return self::calculateWithDecayFactor($rankingInfo->getTermPositions());
    }

    public static function calculateWithDecayFactor(TermPositions $termPositions, float $decayFactor = 0.1): float
    {
        $consecutivePositionsPerAttribute = array_fill_keys($termPositions->getMatchingAttributes(), []);

        // No terms (shouldn't happen anyway) or just one, there's no distance between terms to calculate
        if (\count($termPositions->getTerms()) <= 1) {
            return 1.0;
        }

        foreach ($termPositions->getTerms() as $term) {
            if (!$term->hasMatches()) {
                continue;
            }

            foreach ($term->getMatches() as $match) {
                $lastPosition = end($consecutivePositionsPerAttribute[$match->getAttribute()]);

                // First element
                if ($lastPosition === false) {
                    $consecutivePositionsPerAttribute[$match->getAttribute()][] = $match->getFirstPosition()->position;
                } else {
                    $positionAfter = $match->getPositionAfter($lastPosition);
                    if ($positionAfter) {
                        $consecutivePositionsPerAttribute[$match->getAttribute()][] = $positionAfter->position;
                    }
                }
            }
        }

        $allAdjacentPerAttribute = array_fill_keys($termPositions->getMatchingAttributes(), true);
        $proximityPerAttribute = array_fill_keys($termPositions->getMatchingAttributes(), 0);
        $totalTermsRelevantForProximity = $termPositions->getTotalMatchingTerms() - 1; // Minus one for the first term which can never have a distance

        foreach ($consecutivePositionsPerAttribute as $attribute => $positions) {
            $positionPrev = null;
            $totalProximity = 0;
            $positionsCount = \count($positions);

            // Not found for every term, cannot be a 100% match
            if ($positionsCount !== $termPositions->getTotalMatchingTerms()) {
                $allAdjacentPerAttribute[$attribute] = false;
            }

            if ($positionsCount === 1) {
                $proximityPerAttribute[$attribute] = 1.0 / $termPositions->getTotalMatchingTerms();
                continue;
            }

            foreach ($positions as $position) {
                if ($positionPrev === null) {
                    $positionPrev = $position;
                    continue;
                }

                $distance = $position - $positionPrev;

                if ($distance !== 1) {
                    $allAdjacentPerAttribute[$attribute] = false;
                }

                // Calculate proximity with decay function using the distance
                $proximity = exp(-1 * $decayFactor * $distance);
                $totalProximity += $proximity;
                $positionPrev = $position;
            }

            $proximityPerAttribute[$attribute] = $totalProximity / $totalTermsRelevantForProximity;
        }

        // If all terms are adjacent for one attribute, we found a 100% match
        foreach ($allAdjacentPerAttribute as $allAdjacent) {
            if ($allAdjacent) {
                return 1.0;
            }
        }

        if ($proximityPerAttribute === []) {
            return 1.0;
        }

        // Otherwise we take the highest proximity of all attributes
        return max($proximityPerAttribute);
    }
}
