<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Loupe\Loupe\Internal\Util;

class BulkUpserter
{
    /**
     * The RETURNING clause was only introduced in SQLite 3.35.0. For older versions, a fallback
     * without RETURNING is used.
     */
    public const MIN_SQLITE_VERSION_FOR_RETURNING = '3.35.0';

    public function __construct(
        private Connection $connection,
        private BulkUpsertConfig $bulkUpsertConfig,
        private int $variableLimit,
        private string $sqliteVersion,
    ) {

    }

    /**
     * @param array<mixed> $results
     * @return array<string, array<mixed>>
     */
    public static function convertResultsToIndexedArray(array $results, string $indexColumn): array
    {
        $data = [];

        foreach ($results as $row) {
            \assert(isset($row[$indexColumn]) && \count($row) >= 2);
            $id = $row[$indexColumn];
            unset($row[$indexColumn]);
            $data[$id] = $row;
        }

        return $data;
    }

    /**
     * @param array<mixed> $results
     * @return array<string|int, mixed>
     */
    public static function convertResultsToKeyValueArray(array $results): array
    {
        $data = [];

        foreach ($results as $row) {
            \assert(\count($row) >= 2);
            [$key, $value] = array_values($row);
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public function execute(): array
    {
        $updateColumns = array_values(array_diff($this->bulkUpsertConfig->getRowColumns(), $this->bulkUpsertConfig->getUniqueColumns()));
        $chunkSize = max((int) round($this->variableLimit / \count($this->bulkUpsertConfig->getRowColumns()), 0, PHP_ROUND_HALF_DOWN), 1);
        $results = [];
        $supportsReturning = version_compare($this->sqliteVersion, self::MIN_SQLITE_VERSION_FOR_RETURNING, '>=');

        foreach (Util::arrayChunk($this->bulkUpsertConfig->getRows(), $chunkSize) as $chunk) {
            $chunkResults = $supportsReturning
                // Modern path: INSERT .. ON CONFLICT .. DO UPDATE [RETURNING]
                ? $this->executeModern($chunk, $updateColumns)
                // Legacy path for SQLite < 3.35.0 without RETURNING support
                : $this->executeLegacy($chunk, $updateColumns);

            $results = [...$results, ...$chunkResults];
        }

        return $results;
    }

    /**
     * @param array<array<int, mixed>> $rows
     * @param array<int|string> $columnKeys
     * @param array<int<0, max>|string, mixed> $parameters
     */
    private function buildTuples(array $rows, array $columnKeys, array &$parameters): string
    {
        $tuple = $this->placeholdersRow(\count($columnKeys));
        $tuples = [];

        foreach ($rows as $row) {
            foreach ($columnKeys as $columnKey) {
                $parameters[] = $row[$columnKey] ?? null;
            }

            $tuples[] = $tuple;
        }

        return implode(',', $tuples);
    }

    /**
     * @param array<array<int, mixed>> $rows
     * @param array<string> $updateColumns
     * @param array<int<0, max>|string, mixed> $parameters
     */
    private function buildUpsertSql(array $rows, array $updateColumns, ConflictMode $conflictMode, array &$parameters): string
    {
        $values = $this->buildTuples($rows, array_keys($this->bulkUpsertConfig->getRowColumns()), $parameters);

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO ',
            $this->bulkUpsertConfig->getTable(),
            implode(', ', $this->bulkUpsertConfig->getRowColumns()),
            $values,
            implode(', ', $this->bulkUpsertConfig->getUniqueColumns()),
        );

        $sql .= match ($conflictMode) {
            ConflictMode::Update => \sprintf('UPDATE SET %s', $this->updateSetExcluded($updateColumns)),
            ConflictMode::Ignore => 'NOTHING',
        };

        if ($this->bulkUpsertConfig->getChangeDetectingColumn()) {
            $sql .= \sprintf(
                ' WHERE %s.%s IS NOT excluded.%s',
                $this->bulkUpsertConfig->getTable(),
                $this->bulkUpsertConfig->getChangeDetectingColumn(),
                $this->bulkUpsertConfig->getChangeDetectingColumn()
            );
        }

        return $sql;
    }

    /**
     * Fallback for SQLite < 3.35.0 which does not support the RETURNING clause: executes the upsert
     * without RETURNING and reconstructs the result with a follow-up SELECT over the unique columns
     * of the just upserted rows.
     *
     * In contrast to the modern path, this cannot cheaply detect which conflicting rows were skipped
     * because of the change detecting column, so it conservatively returns all upserted rows.
     *
     * @param array<array<int, mixed>> $rows
     * @param array<string> $updateColumns
     * @return array<mixed>
     */
    private function executeLegacy(array $rows, array $updateColumns): array
    {
        $parameters = [];
        $sql = $this->buildUpsertSql($rows, $updateColumns, $this->bulkUpsertConfig->getConflictMode(), $parameters);
        $this->executeStatement($sql, $parameters);

        if ($this->bulkUpsertConfig->getReturningColumns() === []) {
            return [];
        }

        return $this->selectReturningColumns($rows);
    }

    /**
     * @param array<array<int, mixed>> $rows
     * @param array<string> $updateColumns
     * @return array<mixed>
     */
    private function executeModern(array $rows, array $updateColumns): array
    {
        $conflictMode = $this->bulkUpsertConfig->getConflictMode();
        $returningColumns = $this->bulkUpsertConfig->getReturningColumns();

        // If returning columns are desired but there are no columns to update, this would not return any data.
        // Hence, we have to force an UPDATE SET with the unique columns.
        if ($returningColumns !== [] && $updateColumns === []) {
            $conflictMode = ConflictMode::Update;
            $updateColumns = $this->bulkUpsertConfig->getUniqueColumns();
        }

        $parameters = [];
        $sql = $this->buildUpsertSql($rows, $updateColumns, $conflictMode, $parameters);

        if ($returningColumns === []) {
            $this->executeStatement($sql, $parameters);
            return [];
        }

        $sql .= ' RETURNING ' . implode(', ', $returningColumns);
        return $this->executeQuery($sql, $parameters)->fetchAllAssociative();
    }

    /**
     * @param array<int<0, max>|string, mixed> $parameters $parameters
     */
    private function executeQuery(string $sql, array $parameters): Result
    {
        return $this->connection->executeQuery($sql, $parameters, $this->extractDbalTypes($parameters));
    }

    /**
     * @param array<int<0, max>|string, mixed> $parameters
     */
    private function executeStatement(string $sql, array $parameters): int|string
    {
        return $this->connection->executeStatement($sql, $parameters, $this->extractDbalTypes($parameters));
    }

    /**
     * @param array<int<0, max>|string, mixed> $parameters
     * @return array<int<0, max>|string, \Doctrine\DBAL\ParameterType>
     */
    private function extractDbalTypes(array $parameters): array
    {
        $types = [];

        foreach ($parameters as $k => $v) {
            $types[$k] = match (\gettype($v)) {
                'boolean' => ParameterType::BOOLEAN,
                'integer' => ParameterType::INTEGER,
                default => ParameterType::STRING
            };
        }

        return $types;
    }

    private function placeholdersRow(int $n): string
    {
        return '(' . implode(',', array_fill(0, $n, '?')) . ')';
    }

    /**
     * @param array<array<int, mixed>> $rows
     * @return array<mixed>
     */
    private function selectReturningColumns(array $rows): array
    {
        $uniqueColumns = $this->bulkUpsertConfig->getUniqueColumns();
        $columnKeysByName = array_flip($this->bulkUpsertConfig->getRowColumns());
        $uniqueColumnKeys = [];

        foreach ($uniqueColumns as $uniqueColumn) {
            \assert(isset($columnKeysByName[$uniqueColumn]), 'Unique columns must be part of the row columns.');
            $uniqueColumnKeys[] = $columnKeysByName[$uniqueColumn];
        }

        $parameters = [];
        $sql = \sprintf(
            'SELECT %s FROM %s WHERE (%s) IN (VALUES %s)',
            implode(', ', $this->bulkUpsertConfig->getReturningColumns()),
            $this->bulkUpsertConfig->getTable(),
            implode(', ', $uniqueColumns),
            $this->buildTuples($rows, $uniqueColumnKeys, $parameters),
        );

        return $this->executeQuery($sql, $parameters)->fetchAllAssociative();
    }

    /**
     * @param array<string> $columns
     */
    private function updateSetExcluded(array $columns): string
    {
        if ($columns === []) {
            return 'NOTHING';
        }

        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $column . ' = excluded.' . $column;
        }
        return implode(', ', $parts);
    }
}
