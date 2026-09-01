<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Index\BulkUpserter;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Loupe\Loupe\Internal\ConnectionPool;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpsertConfig;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserter;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserterFactory;
use Loupe\Loupe\Internal\Index\BulkUpserter\ConflictMode;
use Loupe\Loupe\Logger\InMemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BulkUpserterTest extends TestCase
{
    #[DataProvider('jsonModesProvider')]
    public function testJsonTransportPreservesTypesAndConflictModes(bool $jsonEachAvailable): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement(
            'CREATE TABLE test_values (id INTEGER PRIMARY KEY, boolean_value BOOLEAN, integer_value INTEGER, float_value REAL, numeric_string TEXT, plain_string TEXT, nullable_value TEXT)',
        );
        $columns = ['id', 'boolean_value', 'integer_value', 'float_value', 'numeric_string', 'plain_string', 'nullable_value'];

        $rows = [
            [1, false, 42, 1.25, '123', 'text', null],
            [2, true, -7, -2.5, '001', 'other', null],
        ];

        $insertConfig = BulkUpsertConfig::create('test_values', $columns, $rows, ['id'], ConflictMode::Update)
            ->withReturningColumns(['id', 'plain_string'])
        ;
        $results = $this->upsert($connection, $insertConfig, $jsonEachAvailable);

        $this->assertSame([['id' => 1, 'plain_string' => 'text'], ['id' => 2, 'plain_string' => 'other']], $results);
        $storageTypes = $connection->fetchNumeric(
            'SELECT typeof(boolean_value), typeof(integer_value), typeof(float_value), typeof(numeric_string), typeof(plain_string), typeof(nullable_value) FROM test_values WHERE id = 1',
        );
        $this->assertSame(['integer', 'integer', 'real', 'text', 'text', 'null'], $storageTypes);

        $updateConfig = BulkUpsertConfig::create(
            'test_values',
            $columns,
            [[1, true, 84, 2.5, '456', 'updated', null]],
            ['id'],
            ConflictMode::Update,
        );
        $this->upsert($connection, $updateConfig, $jsonEachAvailable);
        $updatedRow = $connection->fetchNumeric(
            'SELECT boolean_value, integer_value, float_value, numeric_string, plain_string, nullable_value FROM test_values WHERE id = 1',
        );
        $this->assertSame([1, 84, 2.5, '456', 'updated', null], $updatedRow);

        $ignoreConfig = BulkUpsertConfig::create(
            'test_values',
            $columns,
            [[1, false, 0, 0.0, '0', 'ignored', null]],
            ['id'],
            ConflictMode::Ignore,
        );
        (new BulkUpserter($connection, $ignoreConfig, 999, $jsonEachAvailable))->execute();
        $this->assertSame('updated', $connection->fetchOne('SELECT plain_string FROM test_values WHERE id = 1'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function jsonModesProvider(): iterable
    {
        yield 'native json_each' => [true];
        yield 'DBAL fallback' => [false];
    }

    public function testFactoryDetectsJsonEachAndKeepsMiddlewareActive(): void
    {
        $logger = new InMemoryLogger();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $connection->executeStatement('CREATE TABLE test_values (id INTEGER PRIMARY KEY, value TEXT)');
        $factory = new BulkUpserterFactory(new ConnectionPool($connection, $connection));
        $config = BulkUpsertConfig::create(
            'test_values',
            ['id', 'value'],
            [[1, 'logged']],
            ['id'],
            ConflictMode::Update,
        );

        $factory->create($config)->execute();

        $this->assertNotEmpty(array_filter(
            $logger->getRecords(),
            static fn (array $record): bool => str_contains((string) ($record['context']['sql'] ?? ''), 'json_each(?)'),
        ));
    }

    /**
     * @return array<mixed>
     */
    private function upsert(Connection $connection, BulkUpsertConfig $config, bool $jsonEachAvailable): array
    {
        return (new BulkUpserter($connection, $config, 999, $jsonEachAvailable))->execute();
    }

    private function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
