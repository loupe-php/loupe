<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

class BulkUpsertConfig
{
    private string|null $changeDetectingColumn = null;

    /**
     * @var array<string>
     */
    private array $returningColumns = [];

    /**
     * @param non-empty-list<array<mixed>> $rows
     * @param non-empty-array<string> $uniqueColumns
     */
    public function __construct(
        private string $table,
        private array $rows,
        private array $uniqueColumns,
        private ConflictMode $conflictMode
    ) {
        \assert($this->rows !== [], 'Rows cannot be empty.');
        \assert($this->uniqueColumns !== [], 'Unique columns cannot be empty.');
    }

    /**
     * @param non-empty-list<array<mixed>> $rows
     * @param non-empty-array<string> $uniqueColumns
     */
    public static function create(string $table, array $rows, array $uniqueColumns, ConflictMode $conflictMode): self
    {
        return new self($table, $rows, $uniqueColumns, $conflictMode);
    }

    public function getChangeDetectingColumn(): ?string
    {
        return $this->changeDetectingColumn;
    }

    public function getConflictMode(): ConflictMode
    {
        return $this->conflictMode;
    }

    /**
     * @return array<string>
     */
    public function getReturningColumns(): array
    {
        return $this->returningColumns;
    }

    /**
     * @return non-empty-list<array<mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return string[]
     */
    public function getUniqueColumns(): array
    {
        return $this->uniqueColumns;
    }

    public function withChangeDetectingColumn(?string $changeDetectingColumn): self
    {
        $clone = clone $this;
        $clone->changeDetectingColumn = $changeDetectingColumn;
        return $clone;
    }

    /**
     * @param array<string> $returningColumns
     */
    public function withReturningColumns(array $returningColumns): self
    {
        $clone = clone $this;
        $clone->returningColumns = $returningColumns;
        return $clone;
    }
}
