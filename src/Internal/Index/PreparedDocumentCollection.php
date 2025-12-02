<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Loupe\Loupe\Internal\Util;

class PreparedDocumentCollection
{
    /**
     * @var PreparedDocument[]
     */
    private array $documents = [];

    /**
     * @param PreparedDocument[] $documents
     */
    public function __construct(array $documents = [])
    {
        foreach ($documents as $document) {
            $this->add($document);
        }
    }

    public function add(PreparedDocument $document): self
    {
        $this->documents[] = $document;

        return $this;
    }

    /**
     * @return PreparedDocument[]
     */
    public function all(): array
    {
        return $this->documents;
    }

    /**
     * @return array<int>
     */
    public function allInternalIds(): array
    {
        return array_map(static function (PreparedDocument $document) {
            return $document->getInternalId();
        }, $this->documents);
    }

    /**
     * @return \Generator<PreparedDocumentCollection>
     */
    public function chunk(int $size): \Generator
    {
        foreach (Util::arrayChunk($this->documents, $size) as $documents) {
            yield new self($documents);
        }
    }

    public function count(): int
    {
        return \count($this->documents);
    }

    public function empty(): bool
    {
        return $this->documents === [];
    }
}
