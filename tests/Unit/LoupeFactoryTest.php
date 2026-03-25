<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;

class LoupeFactoryTest extends TestCase
{
    use StorageFixturesTestTrait;

    public function testInMemoryClient(): void
    {
        $configuration = Configuration::create();
        $client = (new LoupeFactory())->createInMemory($configuration);
        $this->assertInstanceOf(Loupe::class, $client);
    }

    public function testPersistedClient(): void
    {
        $configuration = Configuration::create();
        $client = (new LoupeFactory())->create($this->createTemporaryDirectory(), $configuration);
        $this->assertInstanceOf(Loupe::class, $client);
    }

    public function testEmptyStringDataDirThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Data directory argument is required and cannot be empty.');

        (new LoupeFactory())->create('', Configuration::create());
    }

    public function testNonExistentDataDirIsCreatedAutomatically(): void
    {
        $dataDir = $this->createTemporaryDirectory() . '/' . uniqid();
        $this->assertDirectoryDoesNotExist($dataDir);

        $loupe = (new LoupeFactory())->create($dataDir, Configuration::create());

        $this->assertDirectoryExists($dataDir);
        $this->assertInstanceOf(Loupe::class, $loupe);
    }

    public function testNestedDataDirIsCreatedAutomatically(): void
    {
        $dataDir = $this->createTemporaryDirectory() . '/' . uniqid() . '/a/b/c';
        $this->assertDirectoryDoesNotExist($dataDir);

        $loupe = (new LoupeFactory())->create($dataDir, Configuration::create());

        $this->assertDirectoryExists($dataDir);
        $this->assertInstanceOf(Loupe::class, $loupe);
    }

    public function testUncreatableDataDirThrows(): void
    {
        $dataDir = '/this_root_path_cannot_exist_' . uniqid('', true) . '/subdir';

        $this->expectException(InvalidConfigurationException::class);

        (new LoupeFactory())->create($dataDir, Configuration::create());
    }
}
