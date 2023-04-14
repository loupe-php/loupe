<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search;

use Terminal42\Loupe\Exception\SortFormatException;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Search\Sorting\AbstractSorter;
use Terminal42\Loupe\Internal\Search\Sorting\Direction;
use Terminal42\Loupe\Internal\Search\Sorting\GeoPoint;
use Terminal42\Loupe\Internal\Search\Sorting\Relevance;
use Terminal42\Loupe\Internal\Search\Sorting\Simple;

class Sorting
{
    private const SORTERS = [Relevance::class, Simple::class, GeoPoint::class];

    /**
     * @param array<AbstractSorter> $sorters
     */
    public function __construct(
        private Engine $engine,
        private array $sorters
    ) {
    }

    public function applySorters(Searcher $searcher): void
    {
        foreach ($this->sorters as $sorter) {
            $sorter->apply($searcher, $this->engine);
        }
    }

    public static function fromArray(array $sort, Engine $engine): self
    {
        $sorters = [];

        foreach ($sort as $v) {
            if (! is_string($v)) {
                throw new SortFormatException('Sort parameters must be an array of strings.');
            }

            $chunks = explode(':', $v, 2);

            if (count($chunks) !== 2 || ! in_array($chunks[1], ['asc', 'desc'], true)) {
                throw SortFormatException::becauseFormat();
            }

            $sorter = null;

            /** @var AbstractSorter $sorterClass */
            foreach (self::SORTERS as $sorterClass) {
                if (! $sorterClass::supports($chunks[0], $engine)) {
                    continue;
                }
                $sorter = $sorterClass::fromString($chunks[0], $engine, Direction::from($chunks[1]));
                break;
            }

            if ($sorter === null) {
                throw SortFormatException::becauseNotSortable($chunks[0]);
            }

            $sorters[] = $sorter;
        }

        return new self($engine, $sorters);
    }
}
