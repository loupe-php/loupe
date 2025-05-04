<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Configuration;
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
}
