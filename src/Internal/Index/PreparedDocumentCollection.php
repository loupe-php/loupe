<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

class PreparedDocumentCollection
{
    /**
     * @var PreparedDocument[]
     */
    private array $documents = [];

    private int $termsCount = 0;

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
        $this->termsCount += $document->getTermsCount();

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
    public function chunkByNumberOfTerms(int $size): \Generator
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than 0.');
        }

        $chunk = [];
        $count = 0;

        foreach ($this->documents as $document) {
            $termsCount = $document->getTermsCount();

            if ($count + $termsCount >= $size) {
                yield new self($chunk);
                $chunk = [];
                $count = 0;
            }

            $chunk[] = $document;
            $count += $termsCount;
        }

        if ($count > 0) {
            yield new self($chunk);
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

    public function getTermsCount(): int
    {
        return $this->termsCount;
    }
}
