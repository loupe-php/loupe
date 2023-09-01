<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\LoupeFactory;
use PHPUnit\Framework\TestCase;

class LoupeFactoryTest extends TestCase
{
    public function testIsSupported(): void
    {
        $factory = new LoupeFactory();
        $this->assertTrue($factory->isSupported());
    }
}
