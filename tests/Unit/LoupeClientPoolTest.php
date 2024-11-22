<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeClientPool;
use PHPUnit\Framework\TestCase;

class LoupeClientPoolTest extends TestCase
{
    public function testMake(): void
    {
        $dir = $this->createTempDir();
        $pool = new LoupeClientPool($dir);
        $client = $pool->make('docs', Configuration::create());

        $this->assertInstanceOf(Loupe::class, $client);
    }

    public function testPaths(): void
    {
        $dir = $this->createTempDir();
        $pool = new LoupeClientPool($dir);

        $docsClient = $pool->make('docs', Configuration::create());
        $this->assertFileExists($dir . '/docs/loupe.db');

        $invoicesClient = $pool->make('invoices', Configuration::create());
        $this->assertFileExists($dir . '/invoices/loupe.db');
    }

    public function testState(): void
    {
        $dir = $this->createTempDir();
        $pool = new LoupeClientPool($dir);

        $this->assertFalse($pool->indexExists('docs'));
        $client = $pool->make('docs', Configuration::create());
        $this->assertTrue($pool->indexExists('docs'));

        $pool->dropIndex('docs');
        $this->assertFalse($pool->indexExists('docs'));
        $this->assertFileDoesNotExist($dir . '/docs/loupe.db');

        $pool->createIndex('docs');
        $this->assertTrue($pool->indexExists('docs'));
        $this->assertFileExists($dir . '/docs/loupe.db');
    }

    protected function createTempDir(): string
    {
        $tmpDataDir = sys_get_temp_dir() . '/' . uniqid('loupe');
        mkdir($tmpDataDir, 0777, true);
        return $tmpDataDir;
    }
}
