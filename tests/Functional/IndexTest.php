<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Logger\InMemoryLogger;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use Loupe\Loupe\Tests\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
            InvalidDocumentException::class,
            'Document ID "2" ("{"age":29,"colors":["Red","Orange"],"departments":[1,3,8],"firstname":"Uta","gender":"female","id":2,"lastname":"Koertig"}") does not match schema: {"departments":"array<string>","firstname":"string","gender":"string","id":"number"}',
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
            InvalidDocumentException::class,
            'Document ID "3" ("{"age":29,"colors":["Red","Orange"],"departments":[1,3,8],"firstname":"Uta","gender":"female","id":3,"lastname":"Koertig"}") does not match schema: {"departments":"array<string>","firstname":"string","gender":"string","id":"number"}',
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
            \sprintf("gender = '%s'", LoupeTypes::VALUE_NULL),
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
            \sprintf("gender = '%s'", LoupeTypes::VALUE_EMPTY),
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

    public function testCanUseUserIdAndDocumentProperties(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['user_id', 'document'])
        ;
        $loupe = $this->createLoupe($configuration);

        $document = [
            'id' => 42,
            'user_id' => 'my-id',
            'document' => 'A38',
        ];
        $loupe->addDocument($document);

        $this->assertSame($document, $loupe->getDocument(42));
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

    public function testIndexingIdenticalDocumentWorksIfConfigChanges(): void
    {
        $dir = $this->createTemporaryDirectory();

        $configuration = Configuration::create()
            ->withSearchableAttributes(['lastname'])
        ;

        $loupe = $this->createLoupe($configuration, $dir);
        $loupe->addDocument(self::getSandraDocument());

        $searchParameters = SearchParameters::create()
            ->withQuery('maier')
            ->withAttributesToRetrieve(['id', 'lastname'])
        ;

        // We indexed sandra, that should match and return a hit
        $this->assertSame(1, $loupe->search($searchParameters)->getTotalHits());

        // We replaced the exact same document, that should still work
        $loupe->addDocument(self::getSandraDocument());
        $this->assertSame(1, $loupe->search($searchParameters)->getTotalHits());

        // Now our configuration changes, and "lastname" is suddenly not searchable anymore but "firstname" is
        $configuration = Configuration::create()
            ->withSearchableAttributes(['firstname'])
        ;
        $loupe = $this->createLoupe($configuration, $dir);

        // Loupe should tell us now that a re-index is needed because the configuration changed
        $this->assertTrue($loupe->needsReindex());

        // Searching for maier again, still returns the document because nobody reindexed. That's just what it is.
        $searchParameters = SearchParameters::create()
            ->withQuery('maier')
            ->withAttributesToRetrieve(['id', 'lastname'])
        ;
        $this->assertSame(1, $loupe->search($searchParameters)->getTotalHits());

        // However, we now do re-index but the document is unchanged!
        // This should then result in 0 results, so we test here that Loupe does not work with identical data if a
        // reindex is necessary
        $loupe->addDocument(self::getSandraDocument());
        $this->assertSame(0, $loupe->search($searchParameters)->getTotalHits());
    }

    public function testIndexMigratesExistingDataAndDoesNotFailOnDatabaseSchemaUpdates(): void
    {
        // Copy the fixture to a temporary directory to prevent other files being created within our git repository
        $tempDir = $this->createTemporaryDirectory();
        (new Filesystem())->copy(Util::fixturesPath('OldDatabaseSchema/v012/loupe.db'), $tempDir . '/loupe.db');
        $loupe = $this->setupLoupeWithDepartments(null, $tempDir);

        $searchParameters = SearchParameters::create()
            ->withFilter("departments = 'Development'")
            ->withAttributesToRetrieve(['id', 'firstname'])
            ->withSort(['firstname:asc']);

        // Searching now results in 0 results because the schema has not been migrated - can only do that on indexing
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);

        // Index new data should not fail and migrate existing data
        $loupe->addDocument(self::getSandraDocument());

        // Should definitely find Sandra now because we've added that, but we should also find the other's of that
        // department, due to auto-migration
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @param class-string<\Throwable> $expectedException
     */
    #[DataProvider('invalidSchemaChangesProvider')]
    public function testInvalidSchemaChanges(array $documents, string $expectedException, string $expectedExceptionMessage): void
    {
        $this->expectException($expectedException);
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

    public function testSkipsAttributesThatAreInvalidButNotSearchableOnSetup(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 1,
            'title' => 'Movie',
            'overview' => 'This is some teaser',
            'irrelevant@attribute-with!invalid-characters' => 'foobar',
        ]);

        $this->assertSame('Movie', $loupe->getDocument(1)['title'] ?? null);
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

    public function testVacuumProbabilityEnsured(): void
    {
        $dir = $this->createTemporaryDirectory();
        $logger = new InMemoryLogger();
        $configuration = Configuration::create()->withLogger($logger)->withVacuumProbability(100);

        $loupe = $this->createLoupe($configuration, $dir);

        $this->assertCount(0, $this->getLoggedStatements($logger, 'PRAGMA incremental_vacuum'));

        $loupe->addDocument([
            'id' => 1,
            'title' => 'Test',
        ]);

        $this->assertCount(1, $this->getLoggedStatements($logger, 'PRAGMA incremental_vacuum'));

        $loupe->addDocument([
            'id' => 2,
            'title' => 'Test',
        ]);

        $this->assertCount(2, $this->getLoggedStatements($logger, 'PRAGMA incremental_vacuum'));
    }

    public function testVacuumProbabilityZero(): void
    {
        $dir = $this->createTemporaryDirectory();
        $logger = new InMemoryLogger();
        $configuration = Configuration::create()->withLogger($logger)->withVacuumProbability(0);

        $loupe = $this->createLoupe($configuration, $dir);

        $this->assertCount(0, $this->getLoggedStatements($logger, 'PRAGMA incremental_vacuum'));

        for ($i = 0; $i < 1000; $i++) {
            $loupe->addDocument([
                'id' => $i,
                'title' => 'Test',
            ]);
        }

        $this->assertCount(0, $this->getLoggedStatements($logger, 'PRAGMA incremental_vacuum'));
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
     * @return array<string>
     */
    private function getLoggedStatements(InMemoryLogger $logger, ?string $filter = null): array
    {
        $records = array_filter($logger->getRecords(), fn (array $record) => str_contains((string) $record['message'], 'Executing statement'));
        $queries = array_map(fn (array $record) => $record['context']['sql'], $records);

        if ($filter) {
            return array_filter($queries, fn (string $query) => str_contains($query, $filter));
        }

        return $queries;
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
