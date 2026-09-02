<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    use StorageFixturesTestTrait;

    public function testJournalMode(): void
    {
        $dir = $this->createTemporaryDirectory();

        $this->assertSame('delete', $this->createConnection($dir)->fetchOne('PRAGMA journal_mode'));

        (new LoupeFactory())->create($dir, Configuration::create());

        $this->assertSame('wal', $this->createConnection($dir)->fetchOne('PRAGMA journal_mode'));
    }

    public function testPageSize(): void
    {
        $dir = $this->createTemporaryDirectory();

        $this->assertSame(4096, $this->createConnection($dir)->fetchOne('PRAGMA page_size'));

        (new LoupeFactory())->create($dir, Configuration::create());

        $this->assertSame(8192, $this->createConnection($dir)->fetchOne('PRAGMA page_size'));
    }

    public function testTermDocumentsSearchIndexIsUnique(): void
    {
        $dir = $this->createTemporaryDirectory();
        $loupe = (new LoupeFactory())->create($dir, Configuration::create());
        $loupe->addDocument(['id' => 1, 'title' => 'The quick brown fox']);

        $connection = $this->createConnection($dir);
        $searchIndex = $connection->fetchAssociative(\sprintf(
            "SELECT `unique`, origin FROM pragma_index_list('%s') WHERE name = '%s'",
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::INDEX_NAME_TERMS_DOCUMENTS_SEARCH,
        ));

        $this->assertSame(['unique' => 1, 'origin' => 'c'], $searchIndex);
        $this->assertFalse($connection->fetchOne(\sprintf(
            "SELECT 1 FROM pragma_index_list('%s') WHERE origin = 'pk'",
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        )));
    }

    private function createConnection(string $dir): Connection
    {
        return DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite://notused:inthis@case/'.$dir.'/loupe.db'));
    }
}
