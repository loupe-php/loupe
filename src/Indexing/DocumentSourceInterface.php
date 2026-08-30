<?php

declare(strict_types=1);

namespace Loupe\Loupe\Indexing;

/**
 * @extends \IteratorAggregate<int|string, array<string, mixed>>
 */
interface DocumentSourceInterface extends \IteratorAggregate
{
    /**
     * A document source must return a fresh iterator on every invocation.
     *
     * @return \Traversable<int|string, array<string, mixed>>
     */
    public function getIterator(): \Traversable;
}
