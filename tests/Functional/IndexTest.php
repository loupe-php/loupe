<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\IndexResult;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Logger\InMemoryLogger;
use Loupe\Loupe\SearchParameters;
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

    public function testDeleteDocument(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $this->assertSame('Star Wars', $loupe->getDocument(11)['title'] ?? '');

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'title'])
            ->withQuery('the') // Search for a word which is likely to appear everywhere to affect the IDF
            ->withHitsPerPage(2)
            ->withShowRankingScore(true)
            ->withSort(['_relevance:desc', 'title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 18,
                    'title' => 'The Fifth Element',
                    '_rankingScore' => 0.83688,
                ],
                [
                    'id' => 16,
                    'title' => 'Dancer in the Dark',
                    '_rankingScore' => 0.74853,
                ],
            ],
            'query' => 'the',
            'hitsPerPage' => 2,
            'page' => 1,
            'totalPages' => 8,
            'totalHits' => 16,
        ]);

        // Delete document and assert it's gone
        $loupe->deleteDocument(11);
        $this->assertNull($loupe->getDocument(11));

        // Search again to ensure the ranking score has changed and one hit less
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 18,
                    'title' => 'The Fifth Element',
                    '_rankingScore' => 0.84228,
                ],
                [
                    'id' => 27,
                    'title' => '9 Songs',
                    '_rankingScore' => 0.76244,
                ],
            ],
            'query' => 'the',
            'hitsPerPage' => 2,
            'page' => 1,
            'totalPages' => 8,
            'totalHits' => 15,
        ]);
    }

    public function testDeleteDocument_WithoutIndexedDocuments(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);

        // Delete document and assert it's gone
        $loupe->deleteDocument("not_existing_identifier");
        $this->assertNull($loupe->getDocument("not_existing_identifier"));
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
