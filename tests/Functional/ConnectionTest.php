<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Logger\InMemoryLogger;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    use StorageFixturesTestTrait;

    public function testJournalMode(): void
    {
        $dir = $this->createTemporaryDirectory();

        $this->assertSame('delete', $this->createConnection($dir)->fetchOne('PRAGMA journal_mode'));

        $loupe = (new LoupeFactory())->create($dir, Configuration::create());

        $this->assertSame('wal', $this->createConnection($dir)->fetchOne('PRAGMA journal_mode'));
    }

    public function testOptimizationsOnlyRunOnce(): void
    {
        $dir = $this->createTemporaryDirectory();
        $logger = new InMemoryLogger();
        $configuration = Configuration::create()->withLogger($logger);

        $loupe = (new LoupeFactory())->create($dir, $configuration);
        $loupe->addDocument([
            'id' => 1,
            'title' => 'Test',
        ]);

        $loupe = (new LoupeFactory())->create($dir, $configuration);
        $loupe->addDocument([
            'id' => 2,
            'title' => 'Test',
        ]);

        $this->assertCount(1, $this->getLoggedQueries($logger, 'PRAGMA page_size'));
    }

    public function testPageSize(): void
    {
        $dir = $this->createTemporaryDirectory();

        $this->assertSame(4096, $this->createConnection($dir)->fetchOne('PRAGMA page_size'));

        $loupe = (new LoupeFactory())->create($dir, Configuration::create());

        $this->assertSame(8192, $this->createConnection($dir)->fetchOne('PRAGMA page_size'));
    }

    private function createConnection(string $dir): \Doctrine\DBAL\Connection
    {
        return DriverManager::getConnection((new DsnParser())->parse('sqlite3://notused:inthis@case/' . $dir . '/loupe.db'));
    }

    /**
     * @return array<string>
     */
    private function getLoggedQueries(InMemoryLogger $logger, ?string $filter = null): array
    {
        $records = array_filter($logger->getRecords(), fn (array $record) => str_contains((string) $record['message'], 'Executing query'));
        $queries = array_map(fn (array $record) => $record['context']['sql'], $records);

        if ($filter) {
            return array_filter($queries, fn (string $query) => str_contains($query, $filter));
        }

        return $queries;
    }
}
