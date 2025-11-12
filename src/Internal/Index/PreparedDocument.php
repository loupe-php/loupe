<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Loupe\Loupe\Internal\Index\PreparedDocument\MultiAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\SingleAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\Term;
use Loupe\Loupe\Internal\Util;

final class PreparedDocument
{
    private int|null $internalId = null;

    /**
     * @var array<MultiAttribute>
     */
    private array $multiAttributes = [];

    /**
     * @var array<SingleAttribute>
     */
    private array $singleAttributes = [];

    /**
     * @var array<Term>
     */
    private array $terms = [];

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        private string $userId,
        private array $document
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(): array
    {
        return $this->document;
    }

    public function getInternalId(): int
    {
        if ($this->internalId === null) {
            throw new \LogicException('Must set the internal ID first, this should not happen.');
        }

        return $this->internalId;
    }

    public function getJsonEncodedDocumentData(): string
    {
        return Util::encodeJson($this->document);
    }

    /**
     * @return MultiAttribute[]
     */
    public function getMultiAttributes(): array
    {
        return $this->multiAttributes;
    }

    /**
     * @return SingleAttribute[]
     */
    public function getSingleAttributes(): array
    {
        return $this->singleAttributes;
    }

    /**
     * @return Term[]
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function withInternalId(int $internalId): self
    {
        $clone = clone $this;
        $clone->internalId = $internalId;
        return $clone;
    }

    /**
     * @param array<MultiAttribute> $multiAttributes
     */
    public function withMultiAttributes(array $multiAttributes): self
    {
        $clone = clone $this;
        $clone->multiAttributes = $multiAttributes;
        return $clone;
    }

    /**
     * @param array<SingleAttribute> $singleAttributes
     */
    public function withSingleAttributes(array $singleAttributes): self
    {
        $clone = clone $this;
        $clone->singleAttributes = $singleAttributes;
        return $clone;
    }

    /**
     * @param array<Term> $terms
     */
    public function withTerms(array $terms): self
    {
        $clone = clone $this;
        $clone->terms = $terms;
        return $clone;
    }
}
