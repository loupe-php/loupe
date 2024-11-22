<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase
{
    use FunctionalTestTrait;

    /**
     * @return iterable<array{distance: int}>
     */
    public static function distanceFilterProvider(): iterable
    {
        yield [
            'distance' => 4_587_758,
        ];
        yield [
            'distance' => 4_587_759,
        ];
        yield [
            'distance' => 4_642_695,
        ];
        yield [
            'distance' => 4_642_696,
        ];
        yield [
            'distance' => 6_000_000,
        ];
    }

    public static function emptyFilterProvider(): \Generator
    {
        yield 'IS EMPTY on multiple attribute' => [
            'departments IS EMPTY',
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
            ],
        ];

        yield 'IS NOT EMPTY on multiple attribute' => [
            'departments IS NOT EMPTY',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield 'IS EMPTY on single attribute' => [
            'gender IS EMPTY',
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
            ],
        ];

        yield 'IS NOT EMPTY on single attribute' => [
            'gender IS NOT EMPTY',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];
    }

    public static function equalFilterProvider(): \Generator
    {
        yield '= on multiple attribute' => [
            "departments = 'Backoffice'",
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield '!= on multiple attribute' => [
            "departments != 'Backoffice'",
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
            ],
        ];

        yield '= on single attribute' => [
            "gender = 'female'",
            [
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield '!= on single attribute' => [
            "gender != 'female'",
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
            ],
        ];

        yield '= on boolean attribute' => [
            'isActive = false',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
            ],
        ];

        yield '!= on boolean attribute' => [
            'isActive != false',
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];
    }

    public static function highlightingProvider(): \Generator
    {
        yield 'Highlight with matches position only' => [
            'assassin',
            ['title', 'overview'],
            [],
            true,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        'genres' => ['Action', 'Crime'],
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

        yield 'Highlight with all searchable fields' => [
            'assassin',
            ['*'],
            [],
            true,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        'genres' => ['Action', 'Crime'],
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
            'assasin',
            ['title', 'overview'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        'genres' => ['Action', 'Crime'],
                        '_formatted' => [
                            'id' => 24,
                            'title' => 'Kill Bill: Vol. 1',
                            'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
                            'genres' => ['Action', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'assasin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight with custom start and end tag' => [
            'assasin',
            ['title', 'overview'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        'genres' => ['Action', 'Crime'],
                        '_formatted' => [
                            'id' => 24,
                            'title' => 'Kill Bill: Vol. 1',
                            'overview' => 'An <mark>assassin</mark> is shot by her ruthless employer, Bill, and other members of their <mark>assassination</mark> circle – but she lives to plot her vengeance.',
                            'genres' => ['Action', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'assasin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            '<mark>',
            '</mark>',
        ];

        yield 'Highlight without typo' => [
            'assassin',
            ['title', 'overview'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 24,
                        'title' => 'Kill Bill: Vol. 1',
                        'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                        'genres' => ['Action', 'Crime'],
                        '_formatted' => [
                            'id' => 24,
                            'title' => 'Kill Bill: Vol. 1',
                            'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
                            'genres' => ['Action', 'Crime'],
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
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 12,
                        'title' => 'Finding Nemo',
                        'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                        'genres' => ['Animation', 'Family'],
                        '_formatted' => [
                            'id' => 12,
                            'title' => 'Finding Nemo',
                            'overview' => "Nemo, an adventurous young clownfish, is unexpectedly taken from his Great <em>Barrier Reef</em> home to a dentist's office aquarium. It's up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.",
                            'genres' => ['Animation', 'Family'],
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

        yield 'Highlight with match at the end' => [
            'Nemo',
            ['title', 'overview'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 12,
                        'title' => 'Finding Nemo',
                        'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                        'genres' => ['Animation', 'Family'],
                        '_formatted' => [
                            'id' => 12,
                            'title' => 'Finding <em>Nemo</em>',
                            'overview' => "<em>Nemo</em>, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist's office aquarium. It's up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring <em>Nemo</em> home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.",
                            'genres' => ['Animation', 'Family'],
                        ],
                    ],
                ],
                'query' => 'Nemo',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight with array fields' => [
            'Animation',
            ['title', 'overview', 'genres'],
            ['genres'],
            false,
            [
                'hits' => [
                    [
                        'id' => 12,
                        'title' => 'Finding Nemo',
                        'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                        'genres' => ['Animation', 'Family'],
                        '_formatted' => [
                            'id' => 12,
                            'title' => 'Finding Nemo',
                            'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                            'genres' => ['<em>Animation</em>', 'Family'],
                        ],
                    ],
                ],
                'query' => 'Animation',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Highlight with matches position with array fields' => [
            'Family',
            ['title', 'overview', 'genres'],
            ['genres'],
            true,
            [
                'hits' => [
                    [
                        'id' => 12,
                        'title' => 'Finding Nemo',
                        'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                        'genres' => ['Animation', 'Family'],
                        '_formatted' => [
                            'id' => 12,
                            'title' => 'Finding Nemo',
                            'overview' => 'Nemo, an adventurous young clownfish, is unexpectedly taken from his Great Barrier Reef home to a dentist\'s office aquarium. It\'s up to his worrisome father Marlin and a friendly but forgetful fish Dory to bring Nemo home -- meeting vegetarian sharks, surfer dude turtles, hypnotic jellyfish, hungry seagulls, and more along the way.',
                            'genres' => ['Animation', '<em>Family</em>'],
                        ],
                        '_matchesPosition' => [
                            'genres' => [
                                1 => [
                                    [
                                        'start' => 0,
                                        'length' => 6,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 20,
                        'title' => 'My Life Without Me',
                        'overview' => 'A fatally ill mother with only two months to live creates a list of things she wants to do before she dies without telling her family of her illness.',
                        'genres' => ['Drama', 'Romance'],
                        '_formatted' => [
                            'id' => 20,
                            'title' => 'My Life Without Me',
                            'overview' => 'A fatally ill mother with only two months to live creates a list of things she wants to do before she dies without telling her family of her illness.',
                            'genres' => ['Drama', 'Romance'],
                        ],
                        '_matchesPosition' => [
                            'overview' => [
                                0 => [
                                    'start' => 127,
                                    'length' => 6,
                                ],
                            ],
                        ],
                    ],
                ],
                'query' => 'Family',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 2,
            ],
        ];
    }

    public static function inFilterProvider(): \Generator
    {
        yield 'IN on multiple attribute' => [
            "departments IN ('Backoffice', 'Project Management')",
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield 'NOT IN on multiple attribute' => [
            "departments NOT IN ('Backoffice', 'Project Management')",
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
            ],
        ];

        yield 'IN on single attribute' => [
            "gender IN ('female', 'other')",
            [
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield 'NOT IN on single attribute' => [
            "gender NOT IN ('female', 'other')",
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
            ],
        ];
    }

    public static function lowerAndGreaterThanFilters(): \Generator
    {
        yield [
            'rating > 3.5',
            [
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                ],
            ],
        ];

        yield [
            'rating >= 3.5',
            [
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                ],
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                ],
            ],
        ];

        yield [
            'rating < 3.5',
            [
                [
                    'id' => 5,
                    'name' => 'Back to the future',
                    'rating' => 0,
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                    'rating' => 2.5,
                ],
            ],
        ];

        yield [
            'rating <= 3.5',
            [
                [
                    'id' => 5,
                    'name' => 'Back to the future',
                    'rating' => 0,
                ],
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                    'rating' => 2.5,
                ],
            ],
        ];
    }

    public static function nullFilterProvider(): \Generator
    {
        yield 'IS NULL on multiple attribute' => [
            'departments IS NULL',
            [
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
            ],
        ];

        yield 'IS NOT NULL on multiple attribute' => [
            'departments IS NOT NULL',
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

        yield 'IS NULL on single attribute' => [
            'gender IS NULL',
            [
                [
                    'id' => 5,
                    'firstname' => 'Marko',
                ],
            ],
        ];

        yield 'IS NOT NULL on single attribute' => [
            'gender IS NOT NULL',
            [
                [
                    'id' => 3,
                    'firstname' => 'Alexander',
                ],
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                ],
                [
                    'id' => 4,
                    'firstname' => 'Jonas',
                ],
                [
                    'id' => 1,
                    'firstname' => 'Sandra',
                ],
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];
    }

    public static function prefixSearchProvider(): \Generator
    {
        yield 'Searching for "h" should not return any results by default because the minimum prefix length is 3' => [
            'h',
            [],
        ];

        yield 'Searching for "h" should return Huckleberry if minimum prefix length is 1' => [
            'h',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ],
            ],
            1,
        ];

        yield 'Searching for "huckl" should return Huckleberry (no typo)' => [
            'huckl',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ],
            ],
        ];

        yield 'Searching for "hucka" should not return Huckleberry (with typo) because prefix typo search is disabled' => [
            'hucka',
            [],
        ];

        yield 'Searching for "my friend huckl" should return Huckleberry because "huckl" is the last token' => [
            'my friend huckl',
            [
                [
                    'id' => 6,
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ],
            ],
        ];

        yield 'Searching for "huckl is my friend" should not return Huckleberry because "huckl" is not the last token' => [
            'huckl is my friend',
            [
            ],
        ];
    }

    public static function sortWithNullAndNonExistingValueProvider(): \Generator
    {
        yield 'ASC' => [
            ['rating:asc', 'name:asc'],
            [
                [
                    'id' => 5,
                    'name' => 'Back to the future',
                    'rating' => null,
                ],
                [
                    'id' => 4,
                    'name' => 'Interstellar',
                    'rating' => null,
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                ],
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                ],
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                ],
            ],
        ];

        yield 'DESC' => [
            ['rating:desc', 'name:asc'],
            [
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                ],
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                ],
                [
                    'id' => 5,
                    'name' => 'Back to the future',
                    'rating' => null,
                ],
                [
                    'id' => 4,
                    'name' => 'Interstellar',
                    'rating' => null,
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                ],
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

    /**
     * @param array<array<string, mixed>> $expectedHits
     */
    #[DataProvider('emptyFilterProvider')]
    public function testEmptyFilter(string $filter, array $expectedHits): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

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
     * @param array<array<string, mixed>> $expectedHits
     */
    #[DataProvider('equalFilterProvider')]
    public function testEqualFilter(string $filter, array $expectedHits): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

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

    public function testEscapeFilterValues(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['title', 'published'])
        ;

        $document = [
            'id' => 42,
            'title' => "^The 17\" O'Conner && O`Series \n OR a || 1%2 1~2 1*2 \r\n book? \r \twhat \\ text: }{ )( ][ - + // \n\r ok? end$",
            'published' => true,
        ];

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument($document);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'title', 'published'])
            ->withFilter('title = ' . SearchParameters::escapeFilterValue($document['title']))
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [$document],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);

        $searchParameters = $searchParameters->withFilter('published = ' . SearchParameters::escapeFilterValue(true));

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [$document],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testFilteringAndSortingForIdentifier(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['id'])
            ->withSortableAttributes(['id'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            [
                'id' => 42,
                'title' => 'Test 42',
            ],
            [
                'id' => 18,
                'title' => 'Test 18',
            ],
            [
                'id' => 12,
                'title' => 'Test 12',
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['id:desc'])
            ->withFilter('id IN (42, 12)')
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 42,
                'title' => 'Test 42',
            ], [
                'id' => 12,
                'title' => 'Test 12',
            ]],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    public function testGeoBoundingBox(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['location'])
            ->withSearchableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        $dublin = [
            'lat' => 53.3498,
            'lng' => -6.2603,
        ];
        $athen = [
            'lat' => 37.9838,
            'lng' => 23.7275,
        ];

        $searchParameters = SearchParameters::create()
            ->withFilter(sprintf(
                '_geoBoundingBox(location, %s, %s, %s, %s)',
                $dublin['lat'],
                $athen['lng'],
                $athen['lat'],
                $dublin['lng'],
            ))
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => '2',
                    'title' => 'London',
                    'location' => [
                        'lat' => 51.5074,
                        'lng' => -0.1278,
                    ],
                ],
                [
                    'id' => '3',
                    'title' => 'Vienna',
                    'location' => [
                        'lat' => 48.2082,
                        'lng' => 16.3738,
                    ],
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    public function testGeoSearch(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['location', 'type'])
            ->withSortableAttributes(['location', 'rating'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'restaurants');

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'location'])
            ->withFilter('_geoRadius(location, 45.472735, 9.184019, 2000)')
            ->withSort(['_geoPoint(location, 45.472735, 9.184019):asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'name' => "Nàpiz' Milano",
                    'location' => [
                        'lat' => 45.4777599,
                        'lng' => 9.1967508,
                    ],
                ],
                [
                    'id' => 3,
                    'name' => 'Artico Gelateria Tradizionale',
                    'location' => [
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
            ->withAttributesToRetrieve(['id', 'name', 'location'])
            ->withFilter('_geoRadius(location, -34.5567580, -58.4153774, 10000)') // search with negative coordinates
            ->withSort(['_geoPoint(location, -34.5567580, -58.4153774):asc'])  // sort with negative coordinates
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 4,
                    'name' => 'Revire Brasas Bravas',
                    'location' => [
                        'lat' => -34.6002321,
                        'lng' => -58.3823691,
                    ],
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'location', '_geoDistance(location)'])
            ->withSort(['_geoPoint(location, 48.8561446,2.2978204):asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'name' => 'Bouillon Pigalle',
                    'location' => [
                        'lat' => 48.8826517,
                        'lng' => 2.3352748,
                    ],
                    '_geoDistance(location)' => 4024,
                ],
                [
                    'id' => 3,
                    'name' => 'Artico Gelateria Tradizionale',
                    'location' => [
                        'lat' => 45.4632046,
                        'lng' => 9.1719421,
                    ],
                    '_geoDistance(location)' => 641824,
                ],
                [
                    'id' => 1,
                    'name' => "Nàpiz' Milano",
                    'location' => [
                        'lat' => 45.4777599,
                        'lng' => 9.1967508,
                    ],
                    '_geoDistance(location)' => 642336,
                ],
                [
                    'id' => 4,
                    'name' => 'Revire Brasas Bravas',
                    'location' => [
                        'lat' => -34.6002321,
                        'lng' => -58.3823691,
                    ],
                    '_geoDistance(location)' => 11046932,
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 4,
        ]);
    }

    #[DataProvider('distanceFilterProvider')]
    public function testGeoSearchDistances(int $distance): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['location'])
            ->withSearchableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        $searchParameters = SearchParameters::create()
            ->withFilter('_geoRadius(location, 52.52, 13.405, ' . $distance . ')' /* Berlin */)
            ->withAttributesToRetrieve(['id', 'title', 'location'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => '2',
                    'title' => 'London',
                    'location' => [
                        // ~ 932 km
                        'lat' => 51.5074,
                        'lng' => -0.1278,
                    ],
                ],
                [
                    'id' => '3',
                    'title' => 'Vienna',
                    'location' => [
                        // ~ 545 km
                        'lat' => 48.2082,
                        'lng' => 16.3738,
                    ],
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    public function testGeoSearchRetrieveDistanceWithoutSort(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['location'])
            ->withSearchableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        $searchParameters = SearchParameters::create()
            ->withFilter('_geoRadius(location, 52.52, 13.405, 1000000)' /* Berlin */)
            ->withAttributesToRetrieve(['id', 'title', 'location', '_geoDistance(location)'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => '2',
                    'title' => 'London',
                    'location' => [
                        // ~ 932 km
                        'lat' => 51.5074,
                        'lng' => -0.1278,
                    ],
                    '_geoDistance(location)' => 931571,
                ],
                [
                    'id' => '3',
                    'title' => 'Vienna',
                    'location' => [
                        // ~ 545 km
                        'lat' => 48.2082,
                        'lng' => 16.3738,
                    ],
                    '_geoDistance(location)' => 523546,
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
     * @param array<string> $searchableAttributes
     * @param array<string> $attributesToHighlight
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('highlightingProvider')]
    public function testHighlighting(
        string $query,
        array $searchableAttributes,
        array $attributesToHighlight,
        bool $showMatchesPosition,
        array $expectedResults,
        string $highlightStartTag = '<em>',
        string $highlightEndTag = '</em>',
    ): void {
        $configuration = Configuration::create()
            ->withSearchableAttributes($searchableAttributes)
            ->withFilterableAttributes(['genres'])
            ->withSortableAttributes(['title'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToHighlight($attributesToHighlight, $highlightStartTag, $highlightEndTag)
            ->withShowMatchesPosition($showMatchesPosition)
            ->withAttributesToRetrieve(['id', 'title', 'overview', 'genres'])
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

    /**
     * @param array<mixed> $expectedHits
     */
    #[DataProvider('inFilterProvider')]
    public function testInFilter(string $filter, array $expectedHits): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

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
     * @param array<array<string, mixed>> $expectedHits
     */
    #[DataProvider('lowerAndGreaterThanFilters')]
    public function testLowerAndGreaterThanFilters(string $filter, array $expectedHits): void
    {
        $configuration = Configuration::create();

        $configuration = $configuration
            ->withFilterableAttributes(['rating'])
            ->withSortableAttributes(['name'])
            ->withSearchableAttributes(['name'])
        ;

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocuments([
            [
                'id' => 1,
                'name' => 'Star Wars',
                'rating' => 2.5,
            ],
            [
                'id' => 2,
                'name' => 'Indiana Jones',
                'rating' => 3.5,
            ],
            [
                'id' => 3,
                'name' => 'Jurassic Park',
                'rating' => 4,
            ],
            [
                'id' => 4,
                'name' => 'Interstellar',
                'rating' => null,
            ],
            [
                'id' => 5,
                'name' => 'Back to the future',
                'rating' => 0,
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'rating'])
            ->withFilter($filter)
            ->withSort(['name:asc'])
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

    public function testNegatedComplexSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParametersWithoutNegation = SearchParameters::create()
            ->withQuery('friendly mother -boy -"depressed suburban father" father')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParametersWithoutNegation, [
            'hits' => [
                [
                    'id' => 2,
                    'title' => 'Ariel',
                ],
                [
                    'id' => 12,
                    'title' => 'Finding Nemo',
                ],
                [
                    'id' => 20,
                    'title' => 'My Life Without Me',
                ],
            ],
            'query' => 'friendly mother -boy -"depressed suburban father" father',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);
    }

    public function testNegatedSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParametersWithoutNegation = SearchParameters::create()
            ->withQuery('appears')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParametersWithoutNegation, [
            'hits' => [
                [
                    'id' => 15,
                    'title' => 'Citizen Kane',
                ],
                [
                    'id' => 17,
                    'title' => 'The Dark',
                ],
            ],
            'query' => 'appears',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);

        $searchParametersWithNegation = SearchParameters::create()
            ->withQuery('appears -disappears')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParametersWithNegation, [
            'hits' => [
                [
                    'id' => 15,
                    'title' => 'Citizen Kane',
                ],
            ],
            'query' => 'appears -disappears',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testNegatedSearchPhrases(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParametersWithoutNegation = SearchParameters::create()
            ->withQuery('life "new life"')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParametersWithoutNegation, [
            'hits' => [
                [
                    'id' => 14,
                    'title' => 'American Beauty',
                ],
                [
                    'id' => 2,
                    'title' => 'Ariel',
                ],
                [
                    'id' => 15,
                    'title' => 'Citizen Kane',
                ],
                [
                    'id' => 16,
                    'title' => 'Dancer in the Dark',
                ],
                [
                    'id' => 13,
                    'title' => 'Forrest Gump',
                ],
                [
                    'id' => 20,
                    'title' => 'My Life Without Me',
                ],
            ],
            'query' => 'life "new life"',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 6,
        ]);

        $searchParametersWithNegation = SearchParameters::create()
            ->withQuery('life -"new life"')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParametersWithNegation, [
            'hits' => [
                [
                    'id' => 14,
                    'title' => 'American Beauty',
                ],
                [
                    'id' => 15,
                    'title' => 'Citizen Kane',
                ],
                [
                    'id' => 16,
                    'title' => 'Dancer in the Dark',
                ],
                [
                    'id' => 13,
                    'title' => 'Forrest Gump',
                ],
                [
                    'id' => 20,
                    'title' => 'My Life Without Me',
                ],
            ],
            'query' => 'life -"new life"',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 5,
        ]);
    }

    /**
     * @param array<array<string, mixed>> $expectedHits
     */
    #[DataProvider('nullFilterProvider')]
    public function testNullFilter(string $filter, array $expectedHits): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

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
            ->withQuery('I like Star Wars')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 28,
                    'title' => 'Apocalypse Now',
                ],
                [
                    'id' => 25,
                    'title' => 'Jarhead',
                ],
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => 'I like Star Wars',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        // Now let's search for "Star Wars" which should return "Star Wars" only.
        $searchParameters = $searchParameters->withQuery('I like "Star Wars"');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => 'I like "Star Wars"',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testPhraseSearchOnlyConsidersIdenticalAttributes(): void
    {
        $configuration = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            [
                'id' => 1,
                'title' => 'Star Wars',
                'overview' => 'Galaxies and stuff',
            ],
            [
                'id' => 2,
                'title' => 'Clone Wars',
                'overview' => 'Star gazers are everywhere',
            ],
        ]);

        // "Wars" appears at second position in document ID 2, so we have to make sure for phrases we only search
        // within the same attributes
        $searchParameters = SearchParameters::create()
            ->withQuery('"Star Wars"')
            ->withAttributesToRetrieve(['id', 'title'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
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

    /**
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('prefixSearchProvider')]
    public function testPrefixSearch(string $query, array $expectedResults, int $minTokenLengthForPrefixSearch = null): void
    {
        $configuration = Configuration::create();

        if ($minTokenLengthForPrefixSearch !== null) {
            $configuration = $configuration->withMinTokenLengthForPrefixSearch($minTokenLengthForPrefixSearch);
        }

        $loupe = $this->setupLoupeWithDepartmentsFixture($configuration);

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedResults,
            'query' => $query,
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => \count($expectedResults) === 0 ? 0 : 1,
            'totalHits' => \count($expectedResults),
        ]);
    }

    public function testPrefixSearchAndHighlightingWithTypoSearchEnabled(): void
    {
        $typoTolerance = TypoTolerance::create()->withEnabledForPrefixSearch(true);
        $configuration = Configuration::create()->withTypoTolerance($typoTolerance);
        $loupe = $this->setupLoupeWithMoviesFixture($configuration);

        $searchParameters = SearchParameters::create()
            ->withQuery('assat')
            ->withAttributesToRetrieve(['id', 'title', 'overview'])
            ->withSort(['title:asc'])
            ->withAttributesToHighlight(['overview'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 24,
                'title' => 'Kill Bill: Vol. 1',
                'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
                '_formatted' => [
                    'id' => 24,
                    'title' => 'Kill Bill: Vol. 1',
                    'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
                ],
            ]],
            'query' => 'assat',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testPrefixSearchIsNotAppliedToPhraseSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('star')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        // This should find Ariel because "star" matches "starting" in prefix search
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'title' => 'Ariel',
                ],
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => 'star',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);

        // This should not match Ariel because ""star"" (phrase search) does not match "starting"
        $searchParameters = $searchParameters->withQuery('"star"');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => '"star"',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testRankingWithLotsOfMatches(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('Pirates of the Caribbean: The Curse of the Black Pearl')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withShowRankingScore(true)
            ->withHitsPerPage(1)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 22,
                    'title' => 'Pirates of the Caribbean: The Curse of the Black Pearl',
                    '_rankingScore' => 1.0,
                ],
            ],
            'query' => 'Pirates of the Caribbean: The Curse of the Black Pearl',
            'hitsPerPage' => 1,
            'page' => 1,
            'totalPages' => 17,
            'totalHits' => 17,
        ]);
    }

    public function testRelevanceAndRankingScore(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withSortableAttributes(['content'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            [
                'id' => 1,
                'content' => 'The game of life is a game of everlasting learning',
            ],
            [
                'id' => 2,
                'content' => 'The unexamined life is not worth living. Life is life.',
            ],
            [
                'id' => 3,
                'content' => 'Never stop learning',
            ],
            [
                'id' => 4,
                'content' => 'Book title: life learning',
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('life learning')
            ->withAttributesToRetrieve(['id', 'content'])
            ->withShowRankingScore(true)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 4,
                    'content' => 'Book title: life learning',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 1,
                    'content' => 'The game of life is a game of everlasting learning',
                    '_rankingScore' => 0.8496,
                ],
                [
                    'id' => 2,
                    'content' => 'The unexamined life is not worth living. Life is life.',
                    '_rankingScore' => 0.66667,
                ],
                [
                    'id' => 3,
                    'content' => 'Never stop learning',
                    '_rankingScore' => 0.58027,
                ],
            ],
            'query' => 'life learning',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 4,
        ]);

        // Test ranking score threshold
        $searchParameters = $searchParameters->withRankingScoreThreshold(0.8);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 4,
                    'content' => 'Book title: life learning',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 1,
                    'content' => 'The game of life is a game of everlasting learning',
                    '_rankingScore' => 0.8496,
                ],
            ],
            'query' => 'life learning',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    public function testRelevanceAndRankingScoreForNonExistentQueryTerms(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withSortableAttributes(['content'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            [
                'id' => 1,
                'content' => 'The game of life is a game of everlasting learning',
            ],
            [
                'id' => 2,
                'content' => 'The unexamined life is not worth living. Life is life.',
            ],
            [
                'id' => 3,
                'content' => 'Never stop learning',
            ],
            [
                'id' => 4,
                'content' => 'Book title: life learning',
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('foobar life learning')
            ->withAttributesToRetrieve(['id', 'content'])
            ->withShowRankingScore(true)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 4,
                    'content' => 'Book title: life learning',
                    '_rankingScore' => 0.71872,
                ],
                [
                    'id' => 1,
                    'content' => 'The game of life is a game of everlasting learning',
                    '_rankingScore' => 0.64763,
                ],
                [
                    'id' => 2,
                    'content' => 'The unexamined life is not worth living. Life is life.',
                    '_rankingScore' => 0.51236,
                ],
                [
                    'id' => 3,
                    'content' => 'Never stop learning',
                    '_rankingScore' => 0.51236,
                ],
            ],
            'query' => 'foobar life learning',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 4,
        ]);
    }

    public function testSearchingForNumericArrayType(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['months'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 42,
            'months' => [04, 05],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'months'])
            ->withFilter('months IN (04)')
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 42,
                'months' => [04, 05],
            ]],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testSearchWithAttributesToSearchOn(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('four')
            ->withAttributesToSearchOn(['title'])
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 5,
                    'title' => 'Four Rooms',
                ],
            ],
            'query' => 'four',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testSimpleSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('four')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 5,
                    'title' => 'Four Rooms',
                ],
                [
                    'id' => 6,
                    'title' => 'Judgment Night',
                ],
            ],
            'query' => 'four',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
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

    /**
     * @param array<string> $sort
     * @param array<array<string,mixed>> $expectedHits
     */
    #[DataProvider('sortWithNullAndNonExistingValueProvider')]
    public function testSortWithNullAndNonExistingValue(array $sort, array $expectedHits): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['rating'])
            ->withSortableAttributes(['name', 'rating'])
            ->withSearchableAttributes(['name'])
        ;

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocuments([
            [
                'id' => 1,
                'name' => 'Star Wars',
            ],
            [
                'id' => 2,
                'name' => 'Indiana Jones',
                'rating' => 3.5,
            ],
            [
                'id' => 3,
                'name' => 'Jurassic Park',
                'rating' => 4,
            ],
            [
                'id' => 4,
                'name' => 'Interstellar',
                'rating' => null,
            ],
            [
                'id' => 5,
                'name' => 'Back to the future',
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'rating'])
            ->withSort($sort)
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

    public function testStopWordSearch(): void
    {
        $configuration = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        $searchParametersWithoutStopWords = SearchParameters::create()
            ->withQuery('young glaciologist')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        // Should return all movies with the term "young" (OR matching)
        $this->searchAndAssertResults($loupe, $searchParametersWithoutStopWords, [
            'hits' => [
                [
                    'id' => 27,
                    'title' => '9 Songs',
                ],
                [
                    'id' => 12,
                    'title' => 'Finding Nemo',
                ],
                [
                    'id' => 18,
                    'title' => 'The Fifth Element',
                ],
            ],
            'query' => 'young glaciologist',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        $searchParametersWithStopWords = SearchParameters::create()
            ->withQuery('young glaciologist')
            ->withStopWords(['young'])
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc'])
        ;

        // Should only return movies with the term "glaciologist" since "young" is a stop word
        $this->searchAndAssertResults($loupe, $searchParametersWithStopWords, [
            'hits' => [
                [
                    'id' => 27,
                    'title' => '9 Songs',
                ],
            ],
            'query' => 'young glaciologist',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    /**
     * @param array<mixed> $expectedResults
     */
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

    public function testWithoutSetup(): void
    {
        $loupe = $this->createLoupe(Configuration::create());
        $searchParameters = SearchParameters::create()->withQuery('foobar');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => 'foobar',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
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

        yield 'Test no match with the default thresholds (Gukcleberry -> Huckleberry -> distance of 4) - no match with defaults' => [
            TypoTolerance::create(),
            'Gukcleberry',
            [
                'hits' => [],
                'query' => 'Gukcleberry',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 0,
                'totalHits' => 0,
            ],
        ];

        yield 'Test no match with the default thresholds (Gukcleberry -> Huckleberry -> distance of 4) - no match with threshold to 3' => [
            TypoTolerance::create()->withTypoThresholds([
                8 => 3,
            ]),
            'Gukcleberry',
            [
                'hits' => [],
                'query' => 'Gukcleberry',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 0,
                'totalHits' => 0,
            ],
        ];

        yield 'Test no match with the default thresholds (Gukcleberry -> Huckleberry -> distance of 4) - match with threshold to 3 and first counts double disabled' => [
            TypoTolerance::create()->withTypoThresholds([
                8 => 3,
            ])->withFirstCharTypoCountsDouble(false),
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

    private function setupLoupeWithDepartmentsFixture(Configuration $configuration = null): Loupe
    {
        if ($configuration === null) {
            $configuration = Configuration::create();
        }

        $configuration = $configuration
            ->withFilterableAttributes(['departments', 'gender', 'isActive'])
            ->withSortableAttributes(['firstname'])
            ->withSearchableAttributes(['firstname', 'lastname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'departments');

        return $loupe;
    }

    private function setupLoupeWithMoviesFixture(Configuration $configuration = null): Loupe
    {
        if ($configuration === null) {
            $configuration = Configuration::create();
        }

        $configuration = $configuration
            ->withFilterableAttributes(['genres'])
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        return $loupe;
    }
}
