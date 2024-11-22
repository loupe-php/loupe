<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use PHPUnit\Framework\TestCase;

class LoupeFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new LoupeFactory();
        $this->assertInstanceOf(Loupe::class, $factory->create('./test', Configuration::create()));
    }

    public function testCreateInMemory(): void
    {
        $factory = new LoupeFactory();
        $this->assertInstanceOf(Loupe::class, $factory->createInMemory(Configuration::create()));
    }

    public function testIsSupported(): void
    {
        $factory = new LoupeFactory();
        $this->assertTrue($factory->isSupported());
    }
}
