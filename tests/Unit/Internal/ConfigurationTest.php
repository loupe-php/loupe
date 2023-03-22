<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Internal\Configuration;

class ConfigurationTest extends TestCase
{
    public function invalidAttributeNameProvider(): \Generator
    {
        yield ['_underscore'];
        yield ['$dollar_sign'];
        yield ['*asterisk'];
        yield ['invalid-dash'];
    }

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

    /**
     * @dataProvider invalidAttributeNameProvider
     */
    public function testInvalidAttributeName(string $attributeName): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Invalid configuration for path "loupe.filterableAttributes": A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed 30 characters. "%s" given.',
                $attributeName
            )
        );

        new Configuration([
            'filterableAttributes' => [$attributeName],
        ]);
    }
}
