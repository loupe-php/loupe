<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

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
        foreach ($documents as $token) {
            $this->add($token);
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

    public function count(): int
    {
        return \count($this->documents);
    }

    public function empty(): bool
    {
        return $this->documents === [];
    }
}
