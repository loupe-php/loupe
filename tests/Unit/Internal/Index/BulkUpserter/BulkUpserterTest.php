<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Index\BulkUpserter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\ConnectionPool;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpsertConfig;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserter;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserterFactory;
use Loupe\Loupe\Internal\Index\BulkUpserter\ConflictMode;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BulkUpserterTest extends TestCase
{
    /**
     * The last SQLite version without RETURNING support. Used to test the fallback no matter which
     * SQLite version is actually installed.
     */
    private const SQLITE_VERSION_WITHOUT_RETURNING = '3.34.1';

    public function testChangeDetectionOnBothPaths(): void
    {
        $config = BulkUpsertConfig::create(
            'documents',
            ['user_id', 'hash'],
            [['unchanged', 'hash-1'], ['changed', 'hash-2-updated'], ['added', 'hash-3']],
            ['user_id'],
            ConflictMode::Update
        )
            ->withChangeDetectingColumn('hash')
            ->withReturningColumns(['user_id', 'id']);

        foreach ([null, self::SQLITE_VERSION_WITHOUT_RETURNING] as $sqliteVersion) {
            $connection = $this->createInMemoryConnection();
            $connection->executeStatement('CREATE TABLE documents (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL UNIQUE, hash TEXT NOT NULL)');
            $connection->executeStatement("INSERT INTO documents (user_id, hash) VALUES ('unchanged', 'hash-1'), ('changed', 'hash-2')");

            $results = $this->executeUpsert($connection, $config, $sqliteVersion);

            $idsByUserId = BulkUpserter::convertResultsToKeyValueArray($connection->fetchAllAssociative('SELECT user_id, id FROM documents'));

            $expectedKeys = $sqliteVersion === null
                // The modern path does not return conflicting rows that were skipped via the change detecting column
                ? ['added', 'changed']
                // The fallback cannot cheaply detect skipped rows, so it conservatively returns all upserted rows
                : ['added', 'changed', 'unchanged'];

            $this->assertResultMap(array_intersect_key($idsByUserId, array_flip($expectedKeys)), $results);
        }
    }

    public function testConflictModeIgnoreWithReturningColumnsOnBothPaths(): void
    {
        $config = BulkUpsertConfig::create(
            'terms',
            ['term', 'length', 'state'],
            [['foo', 3, 10], ['baz', 3, 30], ['qux', 3, 40]],
            ['term', 'state', 'length'], // Deliberately ordered differently than the row columns
            ConflictMode::Ignore
        )->withReturningColumns(['term', 'id']);

        foreach ([null, self::SQLITE_VERSION_WITHOUT_RETURNING] as $sqliteVersion) {
            $connection = $this->createInMemoryConnection();
            $connection->executeStatement('CREATE TABLE terms (id INTEGER PRIMARY KEY AUTOINCREMENT, term TEXT NOT NULL, length INTEGER NOT NULL, state INTEGER NOT NULL, UNIQUE(term, state, length))');
            $connection->executeStatement("INSERT INTO terms (term, length, state) VALUES ('foo', 3, 10), ('bar', 3, 20)");

            // Both paths must return existing and newly inserted rows alike. Using a tiny variable
            // limit to ensure the results are collected correctly across multiple chunks.
            $results = $this->executeUpsert($connection, $config, $sqliteVersion, 6);

            $idsByTerm = BulkUpserter::convertResultsToKeyValueArray($connection->fetchAllAssociative('SELECT term, id FROM terms'));

            // The pre-existing row must have kept its ID
            $this->assertSame(1, $idsByTerm['foo']);
            $this->assertResultMap(array_intersect_key($idsByTerm, array_flip(['baz', 'foo', 'qux'])), $results);
        }
    }

    public function testEngineIndexesAndSearchesWithoutReturningSupport(): void
    {
        $engine = $this->createTestEngine();
        $this->forceSqliteVersionWithoutReturning($engine);

        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'John Doe',
            ],
            [
                'id' => 2,
                'content' => 'Jane Doe',
            ],
        ]);

        $this->assertSame([1], $this->searchIds($engine, 'john'));
        $this->assertSame([1, 2], $this->searchIds($engine, 'doe'));

        // Re-adding an unchanged and an updated document must work as well (change detection)
        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'John Doe',
            ],
            [
                'id' => 2,
                'content' => 'Jane Rocket',
            ],
        ]);

        $this->assertSame([1], $this->searchIds($engine, 'doe'));
        $this->assertSame([2], $this->searchIds($engine, 'rocket'));

        $engine->deleteDocuments([1]);

        $this->assertSame([], $this->searchIds($engine, 'john'));
        $this->assertSame([2], $this->searchIds($engine, 'jane'));
    }

    public function testWithoutReturningColumnsOnFallbackPath(): void
    {
        $connection = $this->createInMemoryConnection();
        $connection->executeStatement('CREATE TABLE relations (a INTEGER NOT NULL, b INTEGER NOT NULL, UNIQUE(a, b))');
        $connection->executeStatement('INSERT INTO relations (a, b) VALUES (1, 2)');

        $config = BulkUpsertConfig::create(
            'relations',
            ['a', 'b'],
            [[1, 2], [3, 4]],
            ['a', 'b'],
            ConflictMode::Ignore
        );

        $this->assertSame([], $this->executeUpsert($connection, $config, self::SQLITE_VERSION_WITHOUT_RETURNING));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM relations'));
    }

    /**
     * @param array<string|int, mixed> $expected
     * @param array<mixed> $results
     */
    private function assertResultMap(array $expected, array $results): void
    {
        $map = BulkUpserter::convertResultsToKeyValueArray($results);
        ksort($map);
        ksort($expected);

        $this->assertSame($expected, $map);
    }

    private function createInMemoryConnection(): Connection
    {
        return DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite:///:memory:'));
    }

    private function createTestEngine(): Engine
    {
        $connectionPool = new ConnectionPool($this->createInMemoryConnection(), $this->createInMemoryConnection());
        $configuration = Configuration::create()->withSearchableAttributes(['content']);

        return new Engine($connectionPool, $configuration, new NullLogger());
    }

    /**
     * @return array<mixed>
     */
    private function executeUpsert(Connection $connection, BulkUpsertConfig $config, string|null $sqliteVersion, int $variableLimit = BulkUpserterFactory::VARIABLE_LIMIT): array
    {
        $sqliteVersion ??= (string) $connection->fetchOne('SELECT sqlite_version()');

        return (new BulkUpserter($connection, $config, $variableLimit, $sqliteVersion))->execute();
    }

    private function forceSqliteVersionWithoutReturning(Engine $engine): void
    {
        $connectionPool = (new \ReflectionProperty($engine, 'connectionPool'))->getValue($engine);
        \assert($connectionPool instanceof ConnectionPool);

        (new \ReflectionProperty($engine, 'bulkUpserterFactory'))->setValue(
            $engine,
            new BulkUpserterFactory($connectionPool, self::SQLITE_VERSION_WITHOUT_RETURNING)
        );
    }

    /**
     * @return array<int>
     */
    private function searchIds(Engine $engine, string $query): array
    {
        $hits = $engine->search(SearchParameters::create()->withQuery($query))->toArray()['hits'] ?? [];
        $ids = array_column($hits, 'id');
        sort($ids);

        return $ids;
    }
}
