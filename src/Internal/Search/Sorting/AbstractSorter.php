<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Search\Sorting;

use Doctrine\DBAL\Query\QueryBuilder;
use Terminal42\Loupe\Internal\Engine;

abstract class AbstractSorter
{
    abstract public function apply(QueryBuilder $queryBuilder, Engine $engine): void;

    abstract public static function fromString(string $value, Engine $engine, Direction $direction): self;

    abstract public static function supports(string $value, Engine $engine): bool;
}
