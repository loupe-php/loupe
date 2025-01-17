<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Ranking;

abstract class AbstractRanker
{
    abstract public static function calculate(RankingInfo $rankingInfo): float;
}
