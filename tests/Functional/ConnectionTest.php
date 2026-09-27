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

    public function testTermDocumentsOccurrenceKeyIsUnique(): void
    {
        $dir = $this->createTemporaryDirectory();
        $loupe = (new LoupeFactory())->create($dir, Configuration::create());
        $loupe->addDocument(['id' => 1, 'title' => 'The quick brown fox']);

        $connection = $this->createConnection($dir);
        $supportsWithoutRowid = IndexInfo::supportsWithoutRowid($connection);
        $indexName = $supportsWithoutRowid ? 'sqlite_autoindex_terms_documents_1' : IndexInfo::INDEX_NAME_TERMS_DOCUMENTS_SEARCH;
        $index = $connection->fetchAssociative(\sprintf(
            "SELECT `unique`, origin FROM pragma_index_list('%s') WHERE name = ?",
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        ), [$indexName]);

        $this->assertSame(
            [
                'unique' => 1,
                'origin' => $supportsWithoutRowid ? 'pk' : 'c',
            ],
            $index,
        );
    }

    public function testCompositeKeyRelationsUseWithoutRowidWhenSupported(): void
    {
        $dir = $this->createTemporaryDirectory();
        $loupe = (new LoupeFactory())->create($dir, Configuration::create());
        $loupe->addDocument(['id' => 1, 'title' => 'The quick brown fox']);

        $connection = $this->createConnection($dir);
        $platformSupportsWithoutRowid = IndexInfo::supportsWithoutRowid($connection);

        foreach ([IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, IndexInfo::TABLE_NAME_PREFIXES_TERMS, IndexInfo::TABLE_NAME_TERMS_DOCUMENTS] as $tableName) {
            $sql = $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $tableName]);

            $this->assertSame($platformSupportsWithoutRowid, str_contains((string) $sql, 'WITHOUT ROWID'));
        }
    }

    private function createConnection(string $dir): Connection
    {
        return DriverManager::getConnection((new DsnParser())->parse('pdo-sqlite://notused:inthis@case/'.$dir.'/loupe.db'));
    }
}
