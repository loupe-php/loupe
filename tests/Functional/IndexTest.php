<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\IndexResult;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Logger\InMemoryLogger;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    use FunctionalTestTrait;
    use StorageFixturesTestTrait;

    public static function invalidSchemaChangesProvider(): \Generator
    {
        yield 'Wrong array values' => [
            [
                self::getSandraDocument(),
                array_merge(self::getUtaDocument(), [
                    'departments' => [1, 3, 8],
                ]),
            ],
            function (IndexResult $indexResult) {
                self::assertSame(1, $indexResult->successfulDocumentsCount());
                self::assertSame(1, $indexResult->erroredDocumentsCount());
                self::assertCount(1, $indexResult->allDocumentExceptions());
                self::assertNull($indexResult->generalException());
                self::assertInstanceOf(InvalidDocumentException::class, $indexResult->exceptionForDocument(2));
                self::assertSame(
                    'Document ID "2" ("{"id":2,"firstname":"Uta","lastname":"Koertig","gender":"female","departments":[1,3,8],"colors":["Red","Orange"],"age":29}") does not match schema: {"id":"number","firstname":"string","gender":"string","departments":"array<string>"}',
                    $indexResult->exceptionForDocument(2)->getMessage()
                );
            },
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

            function (IndexResult $indexResult) {
                self::assertSame(2, $indexResult->successfulDocumentsCount());
                self::assertSame(1, $indexResult->erroredDocumentsCount());
                self::assertCount(1, $indexResult->allDocumentExceptions());
                self::assertNull($indexResult->generalException());
                self::assertInstanceOf(InvalidDocumentException::class, $indexResult->exceptionForDocument(3));
                self::assertSame(
                    'Document ID "3" ("{"id":3,"firstname":"Uta","lastname":"Koertig","gender":"female","departments":[1,3,8],"colors":["Red","Orange"],"age":29}") does not match schema: {"id":"number","firstname":"string","gender":"string","departments":"array<string>"}',
                    $indexResult->exceptionForDocument(3)->getMessage()
                );
            },
        ];
    }

    public static function specialDataTypesAreEscapedProvider(): \Generator
    {
        yield 'Check internal null value is escaped on single attribute (gender IS NULL)' => [
            [
                array_merge(self::getSandraDocument(), [
                    'gender' => null,
                ]),
                // Simulate an entry which by chance exactly matches our internal value for null
                array_merge(self::getUtaDocument(), [
                    'gender' => LoupeTypes::VALUE_NULL,
                ]),
            ],
            'gender IS NULL',
            // Should only return Sandra as this is the one document that really has a NULL value assigned
            [[
                'id' => 1,
                'firstname' => 'Sandra',
            ]],
        ];

        yield 'Check internal null value is escaped on single attribute (gender = <internal value>)' => [
            [
                array_merge(self::getSandraDocument(), [
                    'gender' => null,
                ]),
                // Simulate an entry which by chance exactly matches our internal value for null
                array_merge(self::getUtaDocument(), [
                    'gender' => LoupeTypes::VALUE_NULL,
                ]),
            ],
            sprintf("gender = '%s'", LoupeTypes::VALUE_NULL),
            // Should only return Uta as this is the one document that really has the <internal value> assigned
            [[
                'id' => 2,
                'firstname' => 'Uta',
            ]],
        ];

        yield 'Check internal empty value is escaped on single attribute (gender IS EMPTY)' => [
            [
                array_merge(self::getSandraDocument(), [
                    'gender' => '',
                ]),
                // Simulate an entry which by chance exactly matches our internal value for empty
                array_merge(self::getUtaDocument(), [
                    'gender' => LoupeTypes::VALUE_EMPTY,
                ]),
            ],
            'gender IS EMPTY',
            // Should only return Sandra as this is the one document that really has an empty value assigned
            [[
                'id' => 1,
                'firstname' => 'Sandra',
            ]],
        ];

        yield 'Check internal empty value is escaped on single attribute (gender = <internal value>)' => [
            [
                array_merge(self::getSandraDocument(), [
                    'gender' => '',
                ]),
                // Simulate an entry which by chance exactly matches our internal value for null
                array_merge(self::getUtaDocument(), [
                    'gender' => LoupeTypes::VALUE_EMPTY,
                ]),
            ],
            sprintf("gender = '%s'", LoupeTypes::VALUE_EMPTY),
            // Should only return Uta as this is the one document that really has the <internal value> assigned
            [[
                'id' => 2,
                'firstname' => 'Uta',
            ]],
        ];

        // No need to check  multi attribute cases because you cannot have a multi attribute with the internal
        // values for null or empty string.
    }

    public function testCanFilterAndSortOnNonExistingSchema(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $partialUtaDocument = array_filter(array_merge(self::getUtaDocument(), [
            'departments' => null,
            'firstname' => null,
        ]));

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([$partialUtaDocument]);
        $this->assertSame(1, $loupe->countDocuments());

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname', 'departments'])
            ->withSort(['firstname:asc'])
        ;

        // Not existing field on positive filter should return nothing as partial Uta does not have "Development" in "departments"
        $searchParameters = $searchParameters->withFilter('departments = \'Development\'');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);

        // Not existing field on negative filter should return partial Uta as this matches
        $searchParameters = $searchParameters->withFilter('departments != \'Development\'');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 2,
                'lastname' => 'Koertig',
            ]],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);

        // Adding the entire document should allow to filter by it now
        $loupe->addDocument(self::getUtaDocument());

        $searchParameters = $searchParameters->withFilter('departments = \'Development\'');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 2,
                'firstname' => 'Uta',
                'lastname' => 'Koertig',
                'departments' => ['Development', 'Backoffice'],
            ]],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);

        $searchParameters = $searchParameters->withFilter('departments != \'Development\'');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
    }

    public function testDeleteAllDocuments(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        foreach (range(11, 20) as $id) {
            $this->assertNotNull($loupe->getDocument($id));
        }

        // Delete all documents and assert they're gone
        $loupe->deleteAllDocuments();
        foreach (range(11, 20) as $id) {
            $this->assertNull($loupe->getDocument($id));
        }
    }

    public function testDeleteDocument(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $this->assertSame('Star Wars', $loupe->getDocument(11)['title'] ?? null);
        $this->assertSame('Finding Nemo', $loupe->getDocument(12)['title'] ?? null);

        // Delete document and assert it's gone
        $loupe->deleteDocument(11);
        $this->assertNull($loupe->getDocument(11)['title'] ?? null);

        // Assert the other document is still there
        $this->assertSame('Finding Nemo', $loupe->getDocument(12)['title'] ?? null);
    }

    public function testDeleteDocuments(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $this->assertSame('Star Wars', $loupe->getDocument(11)['title'] ?? '');
        $this->assertSame('Finding Nemo', $loupe->getDocument(12)['title'] ?? '');
        $this->assertSame('Forrest Gump', $loupe->getDocument(13)['title'] ?? '');

        // Delete documents and assert they're gone
        $loupe->deleteDocuments([11, 12]);
        $this->assertNull($loupe->getDocument(11));
        $this->assertNull($loupe->getDocument(12));
        $this->assertSame('Forrest Gump', $loupe->getDocument(13)['title'] ?? '');
    }

    public function testDeleteDocumentWhenNotSetUpYet(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);

        $loupe->deleteDocument('not_existing_identifier');
        $this->assertNull($loupe->getDocument('not_existing_identifier'));
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @param \Closure(IndexResult):void $assert
     */
    #[DataProvider('invalidSchemaChangesProvider')]
    public function testInvalidSchemaChanges(array $documents, \Closure $assert): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $indexResult = $loupe->addDocuments($documents);

        $assert($indexResult);
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
        $tmpDataDir = $this->createTemporaryDirectory();

        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDataDir);
        $loupe->addDocument(self::getSandraDocument());

        $this->assertFalse($loupe->needsReindex());

        $configuration = Configuration::create()
            ->withSearchableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDataDir);

        // Just making sure that it was actually persistent
        $this->assertSame(1, $loupe->countDocuments());

        $this->assertTrue($loupe->needsReindex());
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
     * @param array<array<string, mixed>> $expectedHits
     */
    #[DataProvider('specialDataTypesAreEscapedProvider')]
    public function testSpecialDataTypesAreEscaped(array $documents, string $filter, array $expectedHits): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments($documents);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'firstname'])
            ->withFilter($filter)
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedHits,
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => \count($expectedHits),
        ]);
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
