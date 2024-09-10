<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Search\Sorting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Searcher;

class GeoPoint extends AbstractSorter
{
    private const COORDINATES_RGXP = '((\-?|\+?)?\d+(\.\d+)?),\s*((\-?|\+?)?\d+(\.\d+)?)'; // Maybe we can find a better one?

    private const GEOPOINT_RGXP = '^_geoPoint\((' . Configuration::ATTRIBUTE_NAME_RGXP . '),\s*' . self::COORDINATES_RGXP . '\)$';

    public function __construct(
        private string $attributeName,
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
            'loupe_geo_distance(%f, %f, %s, %s) AS %s',
            $this->lat,
            $this->lng,
            $alias . '.' . $this->attributeName . '_geo_lat',
            $alias . '.' . $this->attributeName . '_geo_lng',
            Searcher::DISTANCE_ALIAS . '_' . $this->attributeName
        ));

        // No need to use the abstract addOrderBy() here because the relevance alias cannot be of our internal null or empty
        // value
        $searcher->getQueryBuilder()->addOrderBy(Searcher::DISTANCE_ALIAS . '_' . $this->attributeName, $this->direction->getSQL());
    }

    public static function fromString(string $value, Engine $engine, Direction $direction): self
    {
        $matches = self::split($value);

        if ($matches === null) {
            throw new \InvalidArgumentException('Invalid string, call supports() first.');
        }

        return new self($matches['attribute'], $direction, $matches['lat'], $matches['lng']);
    }

    public static function supports(string $value, Engine $engine): bool
    {
        $matches = self::split($value);

        if ($matches === null) {
            return false;
        }

        return \in_array($matches['attribute'], $engine->getIndexInfo()->getSortableAttributes(), true);
    }

    /**
     * @return null|array{lat: float, lng: float, attribute: string}
     */
    private static function split(string $value): ?array
    {
        $supports = preg_match('@' . self::GEOPOINT_RGXP . '@', $value, $matches);

        if (!$supports) {
            return null;
        }

        return [
            'lat' => (float) $matches[2],
            'lng' => (float) $matches[5],
            'attribute' => (string) $matches[1],
        ];
    }
}
