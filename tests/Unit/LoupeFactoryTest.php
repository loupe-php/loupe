<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\Tests\Util;
use PHPUnit\Framework\TestCase;

class LoupeFactoryTest extends TestCase
{
    public function testIsSupported(): void
    {
        $factory = new LoupeFactory();
        $this->assertTrue($factory->isSupported());
    }

    public function testInMemoryClient(): void
    {
        $configuration = Configuration::create();
        $client = (new LoupeFactory())->createInMemory($configuration);
        $this->assertInstanceOf(Loupe::class, $client);
    }

    public function testPersistedClient(): void
    {
        $configuration = Configuration::create();
        $client = (new LoupeFactory())->create(Util::fixturesPath('Storage/DB'), $configuration);
        $this->assertInstanceOf(Loupe::class, $client);
    }
}
