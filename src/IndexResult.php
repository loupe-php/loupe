<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Loupe\Loupe\Exception\LoupeExceptionInterface;

final class IndexResult
{
    /**
     * @param array<string|int, LoupeExceptionInterface> $documentExceptions
     */
    public function __construct(
        private int $successfulCount,
        private array $documentExceptions = [],
        private ?LoupeExceptionInterface $generalException = null
    ) {
    }

    /**
     * @return array<string|int, LoupeExceptionInterface>
     */
    public function allDocumentExceptions(): array
    {
        return $this->documentExceptions;
    }

    public function erroredDocumentsCount(): int
    {
        return \count($this->documentExceptions);
    }

    public function exceptionForDocument(int|string $documentId): ?LoupeExceptionInterface
    {
        return $this->documentExceptions[$documentId] ?? null;
    }

    public function generalException(): ?LoupeExceptionInterface
    {
        return $this->generalException;
    }

    public function successfulDocumentsCount(): int
    {
        return $this->successfulCount;
    }
}
