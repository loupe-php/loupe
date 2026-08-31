<?php

declare(strict_types=1);

namespace Loupe\Loupe\Indexing;

final class DocumentSource implements DocumentSourceInterface
{
    /**
     * @param \Closure(): iterable<int|string, array<string, mixed>> $factory
     */
    private function __construct(private readonly \Closure $factory)
    {
    }

    /**
     * @param callable(): iterable<int|string, array<string, mixed>> $factory
     */
    public static function fromFactory(callable $factory): self
    {
        return new self($factory(...));
    }

    public function getIterator(): \Traversable
    {
        yield from ($this->factory)();
    }
}
