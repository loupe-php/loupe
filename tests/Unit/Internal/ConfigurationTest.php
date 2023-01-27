<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Internal\Configuration;

class ConfigurationTest extends TestCase
{
    public function testHash(): void
    {
        $configurationA = new Configuration([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
        ]);

        $configurationB = new Configuration([
            'sortableAttributes' => ['firstname'],
            'filterableAttributes' => ['gender', 'departments'],
        ]);

        $configurationC = new Configuration([
            'sortableAttributes' => ['firstname', 'lastname'],
            'filterableAttributes' => ['gender', 'departments'],
        ]);

        $this->assertTrue($configurationA->getHash() === $configurationB->getHash());
        $this->assertTrue($configurationA->getHash() !== $configurationC->getHash());
        $this->assertTrue($configurationB->getHash() !== $configurationC->getHash());
    }
}
