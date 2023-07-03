<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public static function indexHashProvider(): \Generator
    {
        yield 'Defaults should match' => [
            Configuration::create(),
            Configuration::create(),
            true,
        ];

        yield 'Primary key is relevant' => [
            Configuration::create(),
            Configuration::create()->withPrimaryKey('uuid'),
            false,
        ];

        yield 'Searchable attributes are relevant' => [
            Configuration::create(),
            Configuration::create()->withSearchableAttributes(['title']),
            false,
        ];

        yield 'Filterable attributes are relevant' => [
            Configuration::create(),
            Configuration::create()->withFilterableAttributes(['title']),
            false,
        ];

        yield 'Sortable attributes are relevant' => [
            Configuration::create(),
            Configuration::create()->withSortableAttributes(['title']),
            false,
        ];

        yield 'Disabling typo tolerance is relevant' => [
            Configuration::create(),
            Configuration::create()->withTypoTolerance(TypoTolerance::create()->disable()),
            false,
        ];

        yield 'Alphabet size is relevant' => [
            Configuration::create(),
            Configuration::create()->withTypoTolerance(TypoTolerance::create()->withAlphabetSize(10)),
            false,
        ];

        yield 'Index length is relevant' => [
            Configuration::create(),
            Configuration::create()->withTypoTolerance(TypoTolerance::create()->withIndexLength(10)),
            false,
        ];

        yield 'Typo thresholds are irrelevant for the hash' => [
            Configuration::create(),
            Configuration::create()->withTypoTolerance(TypoTolerance::create()->withTypoThresholds([
                7 => 3,
            ])),
            true,
        ];

        yield 'First char typo counts double is irrelevant' => [
            Configuration::create(),
            Configuration::create()->withTypoTolerance(TypoTolerance::create()->withFirstCharTypoCountsDouble(false)),
            true,
        ];
    }

    public static function invalidAttributeNameProvider(): \Generator
    {
        yield ['_underscore'];
        yield ['$dollar_sign'];
        yield ['*asterisk'];
        yield ['invalid-dash'];
    }

    #[DataProvider('indexHashProvider')]
    public function testGetIndexHash(Configuration $configurationA, Configuration $configurationB, bool $hashesShouldMatch): void
    {
        $this->assertSame($hashesShouldMatch, $configurationA->getIndexHash() === $configurationB->getIndexHash());
    }

    #[DataProvider('invalidAttributeNameProvider')]
    public function testInvalidAttributeName(string $attributeName): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            sprintf(
                'A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed 30 characters. "%s" given.',
                $attributeName
            )
        );

        Configuration::create()->withFilterableAttributes([$attributeName]);
    }
}
