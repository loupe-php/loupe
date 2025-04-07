<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testGetIndexHashChangesOnMutation(): void
    {
        $a = Configuration::create();
        $b = $a->withPrimaryKey('different');

        $this->assertNotSame($a->getIndexHash(), $b->getIndexHash());
    }

    public function testImmutability(): void
    {
        $config = Configuration::create();
        $newConfig = $config->withPrimaryKey('changed');

        $this->assertNotSame($config, $newConfig);
        $this->assertSame('id', $config->getPrimaryKey());
        $this->assertSame('changed', $newConfig->getPrimaryKey());
    }

    public function testInvalidAttributeNameThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        Configuration::create()->withFilterableAttributes(['this one has spaces']);
    }

    public function testInvalidRankingRuleThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        Configuration::create()->withRankingRules(['invalid-rule']);
    }

    public function testToArrayAndFromArray(): void
    {
        $original = Configuration::create()
            ->withDisplayedAttributes(['title', 'description'])
            ->withFilterableAttributes(['category'])
            ->withLanguages(['en', 'fr'])
            ->withMaxQueryTokens(20)
            ->withMinTokenLengthForPrefixSearch(2)
            ->withPrimaryKey('uid')
            ->withRankingRules(['words', 'typo'])
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['popularity'])
            ->withStopWords(['a', 'the'])
            ->withTypoTolerance(
                TypoTolerance::create()
                    ->withAlphabetSize(10)
                    ->withFirstCharTypoCountsDouble(false)
                    ->withIndexLength(5)
                    ->withEnabledForPrefixSearch(true)
                    ->withTypoThresholds([
                        10 => 3,
                        5 => 2,
                    ])
            );

        $array = $original->toArray();
        $reconstructed = Configuration::fromArray($array);

        $this->assertSame($original->toArray(), $reconstructed->toArray());
    }

    public function testTooLongAttributeNameThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $invalidName = str_repeat('a', Configuration::MAX_ATTRIBUTE_NAME_LENGTH + 1);
        Configuration::create()->withFilterableAttributes([$invalidName]);
    }

    public function testToStringAndFromString(): void
    {
        $config = Configuration::create()->withPrimaryKey('custom_id');

        $string = $config->toString();
        $decoded = Configuration::fromString($string);

        $this->assertSame($config->toArray(), $decoded->toArray());
    }

    public function testValidAttributeNameBoundary(): void
    {
        $validName = str_repeat('a', Configuration::MAX_ATTRIBUTE_NAME_LENGTH);
        $config = Configuration::create()->withFilterableAttributes([$validName]);

        $this->assertContains($validName, $config->getFilterableAttributes());
    }
}
