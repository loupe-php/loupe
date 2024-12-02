<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\StateSetIndex;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;
use Toflar\StateSetIndex\StateSetIndex;

class StateSetTest extends TestCase
{
    use StorageFixturesTestTrait;

    public function testStateSetIndexDeletedAfterAllDocumentsDeleted(): void
    {
        $engine = $this->createTestEngine();

        $this->assertStateSetContents($engine, []);

        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'You',
            ],
            [
                'id' => 2,
                'content' => 'Me',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // // You
            2, 12, 50,
            // Me
            2, 10,
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
                'content' => 'John Doe',
            ],
            [
                'id' => 2,
                'content' => 'Jane Doe',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // John
            3, 16, 65, 263,
            // Doe
            1, 8, 34,
            // Jane
            3, 14, 59, 238,
        ]);
    }

    public function testStateSetIndexInstance(): void
    {
        $engine = $this->createTestEngine();

        $this->assertInstanceOf(StateSetIndex::class, $engine->getStateSetIndex());
    }

    public function testStateSetIndexRevisedAfterDocumentDeleted(): void
    {
        $engine = $this->createTestEngine();

        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'Dog Car',
            ],
            [
                'id' => 2,
                'content' => 'Cat Car',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // Dog
            1, 8, 36,
            // Cat
            4, 18, 73,
            // (Dog) Car + (Cat) Car
            4, 18, 75,
        ]);

        $engine->deleteDocuments([1]);

        $this->assertStateSetContents($engine, [
            // Cat
            4, 18, 73,
            // (Cat) Car
            4, 18, 75,
        ]);

        $engine->addDocuments([
            [
                'id' => 3,
                'content' => 'Rat Car',
            ],
        ]);

        $engine->deleteDocuments([2]);

        $this->assertStateSetContents($engine, [
            // Rat
            3, 14, 57,
            // (Rat) Car
            4, 18, 75,
        ]);
    }

    public function testStateSetIndexRevisedAfterDocumentUpdated(): void
    {
        $engine = $this->createTestEngine();

        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'Dog Car',
            ],
            [
                'id' => 2,
                'content' => 'Cat Car',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // Dog
            1, 8, 36,
            // Cat
            4, 18, 73,
            // (Dog) Car + (Cat) Car
            4, 18, 75,
        ]);

        $engine->addDocuments([
            [
                'id' => 1,
                'content' => 'Dog Bus',
            ],
            [
                'id' => 2,
                'content' => 'Cat Van',
            ],
        ]);

        $this->assertStateSetContents($engine, [
            // Dog
            1, 8, 36,
            // Cat
            4, 18, 73,
            // (Dog) Bus
            3, 14, 60,
            // (Cat) Van
            3, 14, 59,
        ]);
    }

    /**
     * @param array<int> $expected
     */
    private function assertStateSetContents(Engine $engine, array $expected): void
    {
        $expected = array_unique($expected);
        sort($expected);

        $set = $engine->getStateSetIndex()->getStateSet();

        $all = $set->all();
        sort($all);

        $dump = require $engine->getDataDir() . '/state_set.php';
        $dump = array_keys($dump);
        sort($dump);

        $this->assertEquals($expected, $all);
        $this->assertEquals($dump, $all);
    }

    private function createTestEngine(): Engine
    {
        $dir = $this->createTemporaryDirectory();
        $path = $dir . '/loupe.db';

        $dbConfig = new DbalConfiguration();
        $dbConfig->setMiddlewares([]);

        $configuration = Configuration::create()->withSearchableAttributes(['content']);

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
