<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Configuration;
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

    public function testIsSupported(): void
    {
        $factory = new LoupeFactory();
        $this->assertTrue($factory->isSupported());
    }

    public function testPersistedClient(): void
    {
        $configuration = Configuration::create();
        $client = (new LoupeFactory())->create($this->createTemporaryDirectory(), $configuration);
        $this->assertInstanceOf(Loupe::class, $client);
    }
}
