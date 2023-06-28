<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class GeoPoint extends AbstractSorter
{
    public const DISTANCE_ALIAS = '_distance';

    private const COORDINATES_RGXP = '((\-?|\+?)?\d+(\.\d+)?),\s*((\-?|\+?)?\d+(\.\d+)?)'; // Maybe we can find a better one?

    private const COORDINATES_RGXP_WITH_BOUNDS = '^' . self::COORDINATES_RGXP . '$';

    private const GEOPOINT_RGXP = '^_geoPoint\(' . self::COORDINATES_RGXP . '\)$';

    public function __construct(
        private Direction $direction,
        private float $lat,
        private float $lng
    ) {
    }

    public function apply(Searcher $searcher, Engine $engine): void
    {
        $alias = $engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        $searcher->getQueryBuilder()->addSelect(sprintf(
            'geo_distance(%f, %f, %s, %s) AS %s',
            $this->lat,
            $this->lng,
            $alias . '._geo_lat',
            $alias . '._geo_lng',
            self::DISTANCE_ALIAS
        ));

        $searcher->getQueryBuilder()->addOrderBy(self::DISTANCE_ALIAS, $this->direction->getSQL());
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        $latlong = self::split($value);
        return new self($direction, $latlong[0], $latlong[1]);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        return self::split($value) !== null;
    }

    /**
     * Returns null if not valid or an array where the first value is the latitude and the second is the longitude.
     */
    private static function split(string $value): ?array
    {
        $supports = preg_match('@' . self::GEOPOINT_RGXP . '@', $value, $matches);

        if (! $supports) {
            return null;
        }

        return [(float) $matches[1], (float) $matches[4]];
    }
}
