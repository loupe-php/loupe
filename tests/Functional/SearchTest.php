<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Config\TypoTolerance;
use Terminal42\Loupe\Configuration;
use Terminal42\Loupe\Loupe;
use Terminal42\Loupe\SearchParameters;

class SearchTest extends TestCase
{
    use FunctionalTestTrait;

    public static function highlightingProvider(): \Generator
    {
        yield 'Highlight with matches position only' => [
            'assassin',
            [],
            true,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        '_matchesPosition' => [
                            'overview' => [
                                [
                                    'start' => 3,
                                    'length' => 8,
                                ],
                                [
                                    'start' => 79,
                                    'length' => 13,
                                ],
                            ],
                        ],
                    ],
                ],
                'query' => 'assassin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight with typo' => [
            'bssassin',
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        '_formatted' => [
                            'id' => 24,
                            'title' => 'Kill Bill: Vol. 1',
                            'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
                        ],
                    ],
                ],
                'query' => 'bssassin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight without typo' => [
            'assassin',
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        '_formatted' => [
                            'id' => 24,
                            'title' => 'Kill Bill: Vol. 1',
                            'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
                        ],
                    ],
                ],
                'query' => 'assassin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight multiple matches with and without typos' => [
            'Barier Reef',
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 12,
                        'title' => 'Finding Nemo',
                        'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                        '_formatted' => [
                            'id' => 12,
                            'title' => 'Finding Nemo',
                            'overview' => "Nemo, an adventurous young clownfish, is unexpectedly taken from his Great <em>Barrier Reef</em> home to a dentist's office aquarium. It's up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.",
                        ],
                    ],
                ],
                'query' => 'Barier Reef',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];
    }

    public function testComplexFilters(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'firstname'])
            ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND gender = 'female'")
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testGeoSearch(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['_geo', 'type'])
            ->withSortableAttributes(['_geo', 'rating'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'restaurants');

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', '_geo'])
            ->withFilter('_geoRadius(45.472735, 9.184019, 2000)')
            ->withSort(['_geoPoint(45.472735, 9.184019):asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'name' => "Nàpiz' Milano",
                    '_geo' => [
                        'lat' => 45.4777599,
                        'lng' => 9.1967508,
                    ],
                ],
                [
                    'id' => 3,
                    'name' => 'Artico Gelateria Tradizionale',
                    '_geo' => [
                        'lat' => 45.4632046,
                        'lng' => 9.1719421,
                    ],
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', '_geo', '_geoDistance'])
            ->withSort(['_geoPoint(48.8561446,2.2978204):asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'name' => 'Bouillon Pigalle',
                    '_geo' => [
                        'lat' => 48.8826517,
                        'lng' => 2.3352748,
                    ],
                    '_geoDistance' => 4024,
                ],
                [
                    'id' => 3,
                    'name' => 'Artico Gelateria Tradizionale',
                    '_geo' => [
                        'lat' => 45.4632046,
                        'lng' => 9.1719421,
                    ],
                    '_geoDistance' => 641824,
                ],
                [
                    'id' => 1,
                    'name' => "Nàpiz' Milano",
                    '_geo' => [
                        'lat' => 45.4777599,
                        'lng' => 9.1967508,
                    ],
                    '_geoDistance' => 642336,
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);
    }

    #[DataProvider('highlightingProvider')]
    public function testHighlighting(string $query, array $attributesToHighlight, bool $showMatchesPosition, array $expectedResults): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withFilterableAttributes(['genres'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToHighlight($attributesToHighlight)
            ->withShowMatchesPosition($showMatchesPosition)
            ->withAttributesToRetrieve(['id', 'title', 'overview'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }

    public function testIgnoresTooLongQuery(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('This is a very long query that should be shortened because it is just way too long')
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => 'This is a very long query that should be shortened',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
    }

    public function testPhraseSearch(): void
    {
        $configuration = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        // Test with regular Star Wars search should list Star Wars first because of relevance
        // sorting, but it should also include other movies with the term "war".
        $searchParameters = SearchParameters::create()
            ->withQuery('Star Wars')
            ->withAttributesToRetrieve(['id', 'title'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
                [
                    'id' => 25,
                    'title' => 'Jarhead',
                ],
                [
                    'id' => 28,
                    'title' => 'Apocalypse Now',
                ],
            ],
            'query' => 'Star Wars',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        // Now let's search for "Star Wars" which should return "Star Wars" only.
        $searchParameters = SearchParameters::create()
            ->withQuery('"Star Wars"')
            ->withAttributesToRetrieve(['id', 'title'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => '"Star Wars"',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testRelevance(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withSortableAttributes(['content'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'relevance');

        $searchParameters = SearchParameters::create()
            ->withQuery('life learning')
            ->withAttributesToRetrieve(['id', 'content'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'content' => 'The game of life is a game of everlasting learning',
                ],
                [
                    'id' => 2,
                    'content' => 'The unexamined life is not worth living',
                ],
                [
                    'id' => 3,
                    'content' => 'Never stop learning',
                ],
            ],
            'query' => 'life learning',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);
    }

    public function testSimpleSearch(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('uta')
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ],
            ],
            'query' => 'uta',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testSorting(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['firstname'])
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'firstname' => 'Alexander',
                ],
                [
                    'firstname' => 'Huckleberry',
                ],
                [
                    'firstname' => 'Jonas',
                ],
                [
                    'firstname' => 'Marko',
                ],
                [
                    'firstname' => 'Sandra',
                ],
                [
                    'firstname' => 'Uta',
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 6,
        ]);

        $searchParameters = $searchParameters->withSort(['firstname:desc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'firstname' => 'Uta',
                ],
                [
                    'firstname' => 'Sandra',
                ],
                [
                    'firstname' => 'Marko',
                ],
                [
                    'firstname' => 'Jonas',
                ],
                [
                    'firstname' => 'Huckleberry',
                ],
                [
                    'firstname' => 'Alexander',
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 6,
        ]);
    }

    #[DataProvider('typoToleranceProvider')]
    public function testTypoTolerance(TypoTolerance $typoTolerance, string $query, array $expectedResults): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
            ->withSearchableAttributes(['firstname', 'lastname'])
        ;

        $configuration = $configuration->withTypoTolerance($typoTolerance);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'departments');

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }

    public static function typoToleranceProvider(): \Generator
    {
        yield 'Test finds exact match when typo tolerance is disabled' => [
            TypoTolerance::create()->disable(),
            'Koertig',
            [
                'hits' => [[
                    'id' => 2,
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ]],
                'query' => 'Koertig',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Test finds exact match with default typo tolerance' => [
            TypoTolerance::create(),
            'Koertig',
            [
                'hits' => [[
                    'id' => 2,
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ]],
                'query' => 'Koertig',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Test tolerates one spelling mistake with default typo tolerance' => [
            TypoTolerance::create(),
            'Koerteg',
            [
                'hits' => [[
                    'id' => 2,
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ]],
                'query' => 'Koerteg',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Test does not tolerate any spelling mistake when typo tolerance is disabled' => [
            TypoTolerance::create()->disable(),
            'Koerteg',
            [
                'hits' => [],
                'query' => 'Koerteg',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 0,
                'totalHits' => 0,
            ],
        ];

        yield 'Test tolerates multiple typos for longer words' => [
            TypoTolerance::create(),
            'Hukcleberry',
            [
                'hits' => [[
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ]],
                'query' => 'Hukcleberry',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Test tolerates typos also at the beginning of a word' => [
            TypoTolerance::create()->withTermThresholds([
                8 => 3,
            ]),
            'Gukcleberry',
            [
                'hits' => [[
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ]],
                'query' => 'Gukcleberry',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];
    }

    private function setupLoupeWithDepartmentsFixture(): Loupe
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
            ->withSearchableAttributes(['firstname', 'lastname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'departments');

        return $loupe;
    }
}
