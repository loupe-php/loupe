<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

class Proximity extends AbstractRanker
{
    private const float DECAY_FACTOR = 0.1;

    public function calculate(array $searchableAttributes, int $totalQueryTokenCount, array $termPositions): float
    {
        $allAdjacent = true;
        $totalProximity = 0;
        $totalTermsRelevantForProximity = \count($termPositions) - 1;
        $positionPrev = null;

        foreach ($termPositions as $positions) {
            if ($positionPrev === null) {
                [$position] = $positions[0];
                $positionPrev = $position;
                continue;
            }

            $distance = 0;

            foreach ($positions as $positionAndAttribute) {
                [$position] = $positionAndAttribute;
                if ($position > $positionPrev) {
                    $distance = $position - $positionPrev;
                    $positionPrev = $position;
                    break;
                }
            }

            if ($distance !== 1) {
                $allAdjacent = false;
            }

            // Calculate proximity with decay function using the distance
            $proximity = exp(-1 * self::DECAY_FACTOR * $distance);
            $totalProximity += $proximity;
        }

        return $allAdjacent ? 1.0 : ($totalTermsRelevantForProximity > 0 ? $totalProximity / $totalTermsRelevantForProximity : 0);
    }
}
