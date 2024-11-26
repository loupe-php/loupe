<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\StateSetIndex;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Exception;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Tests\Util;
use PHPUnit\Framework\TestCase;
use Toflar\StateSetIndex\StateSetIndex;

class StateSetTest extends TestCase
{
    // public function testStateSetIndexDeletedAfterDocumentDeleted(): void
    // {
    //     $engine = $this->createTestEngine();

    //     $engine->addDocuments([
    //         ['id' => 1, 'name' => 'You'],
    //         ['id' => 2, 'name' => 'Me'],
    //     ]);

    //     $this->assertStateSetContents($engine, [
    //         // You
    //         2,
    //         12,
    //         50,
    //         // Me
    //         3,
    //         10,
    //     ]);

    //     $engine->deleteDocuments([1]);

    //     $this->assertStateSetContents($engine, [
    //         // Me
    //         3,
    //         10,
    //     ]);
    // }

    public function testStateSetIndexDeletedAfterAllDocumentsDeleted(): void
    {
        $engine = $this->createTestEngine();

        $engine->addDocuments([
            [
                'id' => 1,
                'name' => 'You',
            ],
            [
                'id' => 2,
                'name' => 'Me',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // You
            2,
            12,
            50,
            // Me
            3,
            10,
        ]);

        $engine->deleteAllDocuments();

        $this->assertStateSetContents($engine, []);
    }

    public function testStateSetIndexEmpty(): void
    {
        $engine = $this->createTestEngine();

        $this->assertStateSetContents($engine, []);
    }

    public function testStateSetIndexFilledFromDocument(): void
    {
        $engine = $this->createTestEngine();

        $this->assertStateSetContents($engine, []);

        $engine->addDocuments([
            [
                'id' => 1,
                'name' => 'John Doe',
            ],
            [
                'id' => 2,
                'name' => 'Jane Doe',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // John
            2,
            3,
            16,
            65,
            263,
            // Doe
            1,
            8,
            34,
            // Jane
            14,
            59,
            238,
        ]);
    }

    public function testStateSetIndexInstance(): void
    {
        $engine = $this->createTestEngine();

        $this->assertInstanceOf(StateSetIndex::class, $engine->getStateSetIndex());
    }

    /**
     * @param array<int> $expected
     */
    private function assertStateSetContents(Engine $engine, array $expected): void
    {
        $set = $engine->getStateSetIndex()->getStateSet();

        $all = $set->all();
        $dump = require $engine->getDataDir() . '/state_set.php';

        $this->assertEquals($all, $expected);
        $this->assertEquals(array_keys($dump), $all);
    }

    private function createTestEngine(): Engine
    {
        $dir = Util::fixturesPath('Storage/DB/' . uniqid());
        $path = $dir . '/loupe.db';

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new Exception('Could not create directory: ' . $dir);
            }
        }

        $dbConfig = new DbalConfiguration();
        $dbConfig->setMiddlewares([]);

        $configuration = Configuration::create();

        $connection = DriverManager::getConnection(
            (new DsnParser())->parse('sqlite3://notused:inthis@case/' . $path)
        );

        $engine = new Engine($connection, $configuration, $dir);
        $indexInfo = $engine->getIndexInfo();
        if ($indexInfo->needsSetup()) {
            $indexInfo->setup([
                'id' => 0,
            ]);
        }

        return $engine;
    }
}
