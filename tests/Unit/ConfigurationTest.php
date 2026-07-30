<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

final class ConfigurationTest extends TestCase
{
    /**
     * @return iterable<array-key, array<mixed>>
     */
    public static function indexHashProvider(): iterable
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

        yield 'Stop words are relevant' => [
            Configuration::create(),
            Configuration::create()->withStopWords(['a', 'the']),
            false,
        ];

        yield 'Synonyms are relevant' => [
            Configuration::create(),
            Configuration::create()->withSynonyms([
                'tv' => ['television'],
            ]),
            false,
        ];

        yield 'Differing synonyms produce differing hashes' => [
            Configuration::create()->withSynonyms([
                'tv' => ['television'],
            ]),
            Configuration::create()->withSynonyms([
                'tv' => ['telly'],
            ]),
            false,
        ];

        yield 'Same synonyms in different order produce the same hash' => [
            Configuration::create()->withSynonyms([
                'sofa' => ['couch'],
                'couch' => ['sofa', 'settee'],
            ]),
            Configuration::create()->withSynonyms([
                'couch' => ['settee', 'sofa'],
                'sofa' => ['couch'],
            ]),
            true,
        ];

        yield 'Typo thresholds are irrelevant' => [
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

    /**
     * @return iterable<array-key, array<mixed>>
     */
    public static function invalidAttributeNameProvider(): iterable
    {
        yield ['_underscore'];
        yield ['$dollar_sign'];
        yield ['*asterisk'];
        yield ['invalid-dash'];
    }

    /**
     * @return iterable<array-key, array<mixed>>
     */
    public static function invalidSynonymProvider(): iterable
    {
        yield 'Multi-word key' => [['san francisco' => ['sf']]];
        yield 'Multi-word value' => [['sf' => ['san francisco']]];
        yield 'Empty string key' => [['' => ['x']]];
        yield 'Empty string value' => [['x' => ['']]];
        yield 'Non-string value in list' => [['x' => [123]]];
        yield 'Empty value list' => [['x' => []]];
        yield 'Non-list value' => [['x' => ['a' => 'b']]];
    }

    public function testSynonymsRoundTrip(): void
    {
        $synonyms = [
            'jacket' => ['parka', 'windbreaker'],
            'couch' => ['sofa'],
        ];

        $configuration = Configuration::create()->withSynonyms($synonyms);

        $this->assertSame(
            [
                'couch' => ['sofa'],
                'jacket' => ['parka', 'windbreaker'],
            ],
            $configuration->getSynonyms(),
        );

        $roundTripped = Configuration::fromArray($configuration->toArray());

        $this->assertSame($configuration->getSynonyms(), $roundTripped->getSynonyms());
        $this->assertSame($configuration->toArray()['synonyms'], $roundTripped->toArray()['synonyms']);
    }

    #[DataProvider('indexHashProvider')]
    public function testGetIndexHash(Configuration $configurationA, Configuration $configurationB, bool $hashesShouldMatch): void
    {
        $this->assertSame($hashesShouldMatch, $configurationA->getIndexHash() === $configurationB->getIndexHash());
    }

    /**
     * @param array<mixed> $synonyms
     */
    #[DataProvider('invalidSynonymProvider')]
    public function testInvalidSynonyms(array $synonyms): void
    {
        $this->expectException(InvalidConfigurationException::class);

        // @phpstan-ignore-next-line intentionally invalid input
        Configuration::create()->withSynonyms($synonyms);
    }

    #[DataProvider('invalidAttributeNameProvider')]
    public function testInvalidAttributeName(string $attributeName): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            \sprintf(
                'A valid attribute name starts with a letter, followed by any number of letters, numbers, or underscores. It must not exceed %d characters. "%s" given.',
                Configuration::MAX_ATTRIBUTE_NAME_LENGTH,
                $attributeName,
            ),
        );

        Configuration::create()->withFilterableAttributes([$attributeName]);
    }

    public function testQueryCacheCanBeConfigured(): void
    {
        $cachePool = $this->createStub(CacheItemPoolInterface::class);
        $configuration = Configuration::create()->withQueryCache($cachePool);

        $this->assertSame($cachePool, $configuration->getQueryCache());
    }

    public function testQueryCacheCanBeDisabledExplicitly(): void
    {
        $configuration = Configuration::create()->withQueryCache(null);

        $this->assertNotInstanceOf(CacheItemPoolInterface::class, $configuration->getQueryCache());
    }
}
