<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Index\IndexInfo;

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

    public function apply(QueryBuilder $queryBuilder, Engine $engine): void
    {
        $alias = $engine->getIndexInfo()
            ->getAliasForTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        $queryBuilder->addSelect(sprintf(
            'geo_distance(%f, %f, %s, %s) AS %s',
            $this->lat,
            $this->lng,
            $alias . '._geo_lat',
            $alias . '._geo_lng',
            self::DISTANCE_ALIAS
        ));

        $queryBuilder->addOrderBy(self::DISTANCE_ALIAS, $this->direction->getSQL());
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
