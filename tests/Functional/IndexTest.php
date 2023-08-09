<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\LoupeExceptionInterface;
use Loupe\Loupe\Logger\InMemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class IndexTest extends TestCase
{
    use FunctionalTestTrait;

    public static function invalidSchemaChangesProvider(): \Generator
    {
        yield 'Wrong array values' => [
            [
                self::getSandraDocument(),
                array_merge(self::getUtaDocument(), [
                    'departments' => [1, 3, 8],
                ]),
            ],
            'Document ID "2" ("{"id":2,"firstname":"Uta","lastname":"Koertig","gender":"female","departments":[1,3,8],"colors":["Red","Orange"],"age":29}") does not match schema: {"id":"number","firstname":"string","gender":"string","departments":"array<string>"}',
        ];

        yield 'Wrong array values when narrowed down' => [
            [
                array_merge(self::getSandraDocument(), [
                    'departments' => [],
                ]),
                self::getUtaDocument(),
                array_merge(self::getUtaDocument(), [
                    'id' => 3,
                    'departments' => [1, 3, 8],
                ]),
            ],
            'Document ID "3" ("{"id":3,"firstname":"Uta","lastname":"Koertig","gender":"female","departments":[1,3,8],"colors":["Red","Orange"],"age":29}") does not match schema: {"id":"number","firstname":"string","gender":"string","departments":"array<string>"}',
        ];
    }

    /**
     * @param array<array<string, mixed>> $documents
     */
    #[DataProvider('invalidSchemaChangesProvider')]
    public function testInvalidSchemaChanges(array $documents, string $expectedExceptionMessage): void
    {
        $this->expectException(LoupeExceptionInterface::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments($documents);
    }

    public function testLogging(): void
    {
        $logger = new InMemoryLogger();
        $configuration = Configuration::create()
            ->withLogger($logger)
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument(self::getSandraDocument());

        $this->assertNotSame(0, \count($logger->getRecords()));
    }

    public function testNullValueIsIrrelevantForDocumentSchema(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocument(self::getSandraDocument());
        $loupe->addDocument([
            'id' => 2,
            'firstname' => 'Uta',
            'lastname' => 'Koertig',
            'gender' => null,
            'departments' => null,
            'colors' => ['Red', 'Orange'],
        ]);

        $this->assertSame(2, $loupe->countDocuments());
    }

    public function testReindex(): void
    {
        $fs = new Filesystem();
        $tmpDb = $fs->tempnam(sys_get_temp_dir(), 'lt');

        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDb);
        $loupe->addDocument(self::getSandraDocument());

        $this->assertFalse($loupe->needsReindex());

        $configuration = Configuration::create()
            ->withSearchableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDb);

        // Just making sure that it was actually persistent
        $this->assertSame(1, $loupe->countDocuments());

        $this->assertTrue($loupe->needsReindex());

        $fs->remove($tmpDb);
    }

    public function testReplacingTheSameDocumentWorks(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);

        $sandra = self::getSandraDocument();

        $uta = [
            'id' => 1, // Same ID, should replace
            'firstname' => 'Uta',
            'lastname' => 'Koertig',
            'gender' => 'female',
            'departments' => ['Development', 'Backoffice'],
            'colors' => ['Red', 'Orange'],
            'age' => 29,
        ];

        $loupe->addDocument($sandra);
        $document = $loupe->getDocument(1);
        $this->assertSame($sandra, $document);

        $loupe->addDocument($uta);
        $document = $loupe->getDocument(1);
        $this->assertSame($uta, $document);
    }

    /**
     * @param array<array<string, mixed>> $documents
     */
    #[DataProvider('validSchemaChangesProvider')]
    public function testValidSchemaChanges(array $documents): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments($documents);
        $this->assertSame(\count($documents), $loupe->countDocuments());
    }

    public static function validSchemaChangesProvider(): \Generator
    {
        yield 'Schema matches exactly' => [
            [
                self::getSandraDocument(),
                self::getUtaDocument(),
            ],
        ];

        yield 'Schema is narrowed down' => [
            [
                array_merge(self::getSandraDocument(), [
                    'firstname' => null,
                    'departments' => [],
                ]),
                self::getUtaDocument(),
            ],
        ];

        yield 'Null is always allowed' => [
            [
                self::getSandraDocument(),
                array_merge(self::getUtaDocument(), [
                    'firstname' => null,
                    'departments' => null,
                ]),
            ],
        ];

        yield 'Empty array is allowed' => [
            [
                self::getSandraDocument(),
                array_merge(self::getUtaDocument(), [
                    'departments' => [],
                ]),
            ],
        ];

        yield 'Omitting attributes is allowed' => [
            [
                self::getSandraDocument(),
                array_diff_key(self::getUtaDocument(), array_flip(['firstname', 'departments'])),
            ],
        ];

        yield 'Adding attributes is allowed' => [
            [
                self::getSandraDocument(),
                array_merge(self::getUtaDocument(), [
                    'new_attribute' => 'valid',
                ]),
            ],
        ];

        yield 'Omitting filterable attributes in first document allowed' => [
            [
                \array_filter(\array_merge(self::getUtaDocument(), ['departments' => null, 'gender' => null])),
                self::getSandraDocument(),
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function getSandraDocument(): array
    {
        return [
            'id' => 1,
            'firstname' => 'Sandra',
            'lastname' => 'Maier',
            'gender' => 'female',
            'departments' => ['Development', 'Engineering'],
            'colors' => ['Green', 'Blue'],
            'age' => 40,
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function getUtaDocument(): array
    {
        return [
            'id' => 2,
            'firstname' => 'Uta',
            'lastname' => 'Koertig',
            'gender' => 'female',
            'departments' => ['Development', 'Backoffice'],
            'colors' => ['Red', 'Orange'],
            'age' => 29,
        ];
    }
}
