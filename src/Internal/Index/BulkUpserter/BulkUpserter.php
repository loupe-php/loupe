<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;

class BulkUpserter
{
    public function __construct(
        private Connection $connection,
        private BulkUpsertConfig $bulkUpsertConfig,
        private int $variableLimit,
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
        $rows = $this->bulkUpsertConfig->getRows();
        $insertColumns = $this->bulkUpsertConfig->getRowColumns();

        $updateColumns = array_values(array_diff($insertColumns, $this->bulkUpsertConfig->getUniqueColumns()));
        $chunkSize = max((int) round($this->variableLimit / \count($insertColumns), 0, PHP_ROUND_HALF_DOWN), 1);
        $results = [];

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $rows = $this->normalizeRows($chunk, $insertColumns);
            // Modern path: INSERT .. ON CONFLICT .. DO UPDATE [RETURNING]
            $results = [...$results, ...$this->executeModern($rows, $insertColumns, $updateColumns)];

            // Depending on the version, we could introduce fallback solutions here
        }

        return $results;
    }

    /**
     * @param non-empty-list<array<mixed>> $rows
     * @param array<string> $columns
     * @param array<int<0, max>|string, mixed> $parameters
     */
    private function buildValuesClause(array $rows, array $columns, array &$parameters): string
    {
        $tuples = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $parameters[] = $row[$column];
            }
            $tuples[] = $this->placeholdersRow(\count($columns));
        }

        return implode(',', $tuples);
    }

    /**
     * @param non-empty-list<array<mixed>> $rows
     * @param array<string> $insertColumns
     * @param array<string> $updateColumns
     * @return array<mixed>
     */
    private function executeModern(array $rows, array $insertColumns, array $updateColumns): array
    {
        $parameters = [];
        $values = $this->buildValuesClause($rows, $insertColumns, $parameters);
        $conflictMode = $this->bulkUpsertConfig->getConflictMode();
        $returningColumns = $this->bulkUpsertConfig->getReturningColumns();

        // If returning columns are desired but there are no columns to update, this would not return any data.
        // Hence, we have to force an UPDATE SET with the unique columns.
        if ($returningColumns !== [] && $updateColumns === []) {
            $conflictMode = ConflictMode::Update;
            $updateColumns = $this->bulkUpsertConfig->getUniqueColumns();
        }

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO ',
            $this->bulkUpsertConfig->getTable(),
            implode(', ', $insertColumns),
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

        if ($returningColumns === []) {
            return $this->executeQuery($sql, $parameters)->fetchAllAssociative();
        }

        $sql .= ' RETURNING ' . implode(', ', $this->bulkUpsertConfig->getReturningColumns());
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

    /**
     * @param non-empty-list<array<mixed>> $rows
     * @param array<string> $insertColumns
     * @return non-empty-list<array<string, mixed>>
     */
    private function normalizeRows(array $rows, array $insertColumns): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalizedRow = [];
            foreach ($insertColumns as $k => $insertColumn) {
                $normalizedRow[$insertColumn] = $row[$k] ?? null;
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }

    private function placeholdersRow(int $n): string
    {
        return '(' . implode(',', array_fill(0, $n, '?')) . ')';
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
