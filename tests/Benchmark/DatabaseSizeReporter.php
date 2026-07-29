<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

final class DatabaseSizeReporter
{
    public function __construct(private readonly string $databasePath)
    {
    }

    /**
     * @return array{
     *     fileSize: int,
     *     allocatedSize: int,
     *     freeSize: int,
     *     objects: list<array{name: string, type: string, table: string, bytes: int}>
     * }
     */
    public function measure(): array
    {
        $pdo = new \PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        $pageSize = $this->queryInteger($pdo, 'PRAGMA page_size');
        $pageCount = $this->queryInteger($pdo, 'PRAGMA page_count');
        $freePageCount = $this->queryInteger($pdo, 'PRAGMA freelist_count');

        clearstatcache(true, $this->databasePath);

        return [
            'fileSize' => (int) filesize($this->databasePath),
            'allocatedSize' => $pageSize * $pageCount,
            'freeSize' => $pageSize * $freePageCount,
            'objects' => $this->measureObjects($pdo),
        ];
    }

    /**
     * @return list<array{name: string, type: string, table: string, bytes: int}>
     */
    private function measureObjects(\PDO $pdo): array
    {
        $statement = $pdo->query(
            <<<'SQL'
                SELECT dbstat.name,
                       COALESCE(sqlite_schema.type, 'internal') AS type,
                       COALESCE(sqlite_schema.tbl_name, '-') AS table_name,
                       SUM(dbstat.pgsize) AS bytes
                FROM dbstat
                LEFT JOIN sqlite_schema ON sqlite_schema.name = dbstat.name
                GROUP BY dbstat.name, sqlite_schema.type, sqlite_schema.tbl_name
                ORDER BY bytes DESC, dbstat.name
                SQL,
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not measure SQLite database objects.');
        }

        $objects = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $objects[] = [
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'table' => (string) $row['table_name'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $objects;
    }

    private function queryInteger(\PDO $pdo, string $query): int
    {
        $statement = $pdo->query($query);

        if (false === $statement) {
            throw new \RuntimeException('Could not execute SQLite query: '.$query);
        }

        return (int) $statement->fetchColumn();
    }
}
