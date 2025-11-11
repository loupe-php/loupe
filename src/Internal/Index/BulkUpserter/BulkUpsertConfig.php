<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

class BulkUpsertConfig
{
    /**
     * @var array<string>
     */
    private array $returningCols = [];

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

    public function getConflictMode(): ConflictMode
    {
        return $this->conflictMode;
    }

    /**
     * @return array<string>
     */
    public function getReturningColumns(): array
    {
        return $this->returningCols;
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

    /**
     * @param array<string> $returningCols
     */
    public function withReturningColumns(array $returningCols): self
    {
        $clone = clone $this;
        $clone->returningCols = $returningCols;
        return $clone;
    }
}
