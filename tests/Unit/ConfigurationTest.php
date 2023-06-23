<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Configuration;
use Terminal42\Loupe\Exception\InvalidConfigurationException;

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
        $configurationA = (new Configuration())
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $configurationB = (new Configuration())
            ->withFilterableAttributes(['gender', 'departments'])
            ->withSortableAttributes(['firstname'])
        ;

        $configurationC = (new Configuration())
            ->withFilterableAttributes(['gender', 'departments'])
            ->withSortableAttributes(['firstname', 'lastname'])
        ;

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
                'A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed 30 characters. "%s" given.',
                $attributeName
            )
        );

        $configuration = new Configuration();
        $configuration->withFilterableAttributes([$attributeName]);
    }
}