<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use Loupe\Loupe\Tests\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class SearchTest extends TestCase
{
    use FunctionalTestTrait;
    use StorageFixturesTestTrait;

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
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                    'id' => 7,
                    'firstname' => 'Thomas',
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
        yield '= on multiple attribute match multiple' => [
            "departments = 'Backoffice' AND departments = 'Development'",
            [
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];

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
                [
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                [
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                [
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                [
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                [
                    'id' => 7,
                    'firstname' => 'Thomas',
                ],
            ],
        ];

        yield 'Combining multiple IN() statements' => [
            "departments IN ('Development') AND colors IN ('Red')",
            [
                [
                    'id' => 2,
                    'firstname' => 'Uta',
                ],
            ],
        ];
    }

    public static function lowerAndGreaterThanAndBetweenFilters(): \Generator
    {
        yield [
            'rating > 3.5',
            [
                [
                    'id' => 6,
                    'name' => 'Gladiator',
                    'rating' => 5,
                    'dates' => [
                        1735689600,
                        1767225600,
                        1798761600,
                    ],
                ],
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                    'dates' => [
                        1740787200,
                        1743465600,
                    ],
                ],
            ],
        ];

        yield [
            'rating >= 3.5',
            [
                [
                    'id' => 6,
                    'name' => 'Gladiator',
                    'rating' => 5,
                    'dates' => [
                        1735689600,
                        1767225600,
                        1798761600,
                    ],
                ],
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                    'dates' => [
                        1738368000,
                        1738454400,
                    ],
                ],
                [
                    'id' => 3,
                    'name' => 'Jurassic Park',
                    'rating' => 4,
                    'dates' => [
                        1740787200,
                        1743465600,
                    ],
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
                    'dates' => [],
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                    'rating' => 2.5,
                    'dates' => [
                        1735689600,
                        1738368000,
                        1740787200,
                    ],
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
                    'dates' => [],
                ],
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                    'dates' => [
                        1738368000,
                        1738454400,
                    ],
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                    'rating' => 2.5,
                    'dates' => [
                        1735689600,
                        1738368000,
                        1740787200,
                    ],
                ],
            ],
        ];

        yield [
            'dates BETWEEN ' . (new \DateTimeImmutable('2025-02-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp() . ' AND ' . (new \DateTimeImmutable('2025-02-04 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            [
                [
                    'id' => 2,
                    'name' => 'Indiana Jones',
                    'rating' => 3.5,
                    'dates' => [
                        1738368000,
                        1738454400,
                    ],
                ],
                [
                    'id' => 1,
                    'name' => 'Star Wars',
                    'rating' => 2.5,
                    'dates' => [
                        1735689600,
                        1738368000,
                        1740787200,
                    ],
                ],
            ],
        ];
    }

    public static function negatedQueryProvider(): \Generator
    {
        yield 'Searching for "-Huckleberry" should return all except him' => [
            '-huckleberry',
            [
                [
                    'firstname' => 'Alexander',
                    'lastname' => 'Abendroth',
                ],
                [
                    'firstname' => 'Jonas',
                    'lastname' => 'Kalb',
                ],
                [
                    'firstname' => 'Marko',
                    'lastname' => 'Gerste',
                ],
                [
                    'firstname' => 'Sandra',
                    'lastname' => 'Maier',
                ],
                [
                    'firstname' => 'Thomas',
                    'lastname' => 'Müller-Lüdenscheidt',
                ],
                [
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ],
            ],
        ];

        yield 'Searching for "-Müller-Lüdenscheidt" should return all except "Müller-Lüdenscheidt"' => [
            '-Müller-Lüdenscheidt',
            [
                [
                    'firstname' => 'Alexander',
                    'lastname' => 'Abendroth',
                ],
                [
                    'firstname' => 'Huckleberry',
                    'lastname' => 'Finn',
                ],
                [
                    'firstname' => 'Jonas',
                    'lastname' => 'Kalb',
                ],
                [
                    'firstname' => 'Marko',
                    'lastname' => 'Gerste',
                ],
                [
                    'firstname' => 'Sandra',
                    'lastname' => 'Maier',
                ],
                [
                    'firstname' => 'Uta',
                    'lastname' => 'Koertig',
                ],
            ],
        ];

        yield 'Searching for "Müller-Lüdenscheidt" should return "Müller-Lüdenscheidt"' => [
            'Müller-Lüdenscheidt',
            [
                [
                    'firstname' => 'Thomas',
                    'lastname' => 'Müller-Lüdenscheidt',
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
                    'id' => 7,
                    'firstname' => 'Thomas',
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
                    'id' => 7,
                    'firstname' => 'Thomas',
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

    public static function searchWithDecompositionProvider(): \Generator
    {
        yield '[German] Test on "Wartungsvertrag"' => [
            'Ich möchte einen Wartungsvertrag verkaufen.',
            'Vertrag',
            [
                'id' => 42,
                'text' => 'Ich möchte einen Wartungsvertrag verkaufen.',
                '_formatted' => [
                    'id' => 42,
                    'text' => 'Ich möchte einen <em>Wartungsvertrag</em> verkaufen.',
                ],
            ],
        ];

        yield '[German] Test on "Künstlerinnengespräch"' => [
            'Ich möchte ein Künstlerinnengespräch führen.',
            'Gespräch',
            [
                'id' => 42,
                'text' => 'Ich möchte ein Künstlerinnengespräch führen.',
                '_formatted' => [
                    'id' => 42,
                    'text' => 'Ich möchte ein <em>Künstlerinnengespräch</em> führen.',
                ],
            ],
        ];
    }

    public static function searchWithFacetsProvider(): \Generator
    {
        yield 'No query and no filters, checking the gender and isActive facet only' => [
            '',
            '',
            ['gender', 'isActive'],
            [
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
                        'firstname' => 'Thomas',
                    ],
                    [
                        'firstname' => 'Uta',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 7,
                'facetDistribution' => [
                    'gender' => [
                        'female' => 2,
                        'male' => 2,
                        'other' => 1,
                    ],
                    'isActive' => [
                        'false' => 2,
                        'true' => 5,
                    ],
                ],
            ],
        ];

        yield 'With query, getting gender facet only' => [
            'finn',
            '',
            ['gender'],
            [
                'hits' => [
                    [
                        'firstname' => 'Huckleberry',
                    ],
                ],
                'query' => 'finn',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
                'facetDistribution' => [
                    'gender' => [
                        'male' => 1,
                    ],
                ],
            ],
        ];

        yield 'With filter, getting gender facet only' => [
            '',
            "departments = 'Backoffice'",
            ['gender'],
            [
                'hits' => [
                    [
                        'firstname' => 'Huckleberry',
                    ],
                    [
                        'firstname' => 'Uta',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 2,
                'facetDistribution' => [
                    'gender' => [
                        'female' => 1,
                        'male' => 1,
                    ],
                ],
            ],
        ];

        yield 'Getting all types of facets' => [
            '',
            '',
            ['gender', 'age', 'departments', 'recentPerformanceScores'],
            [
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
                        'firstname' => 'Thomas',
                    ],
                    [
                        'firstname' => 'Uta',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 7,
                'facetDistribution' => [
                    'gender' => [
                        'female' => 2,
                        'male' => 2,
                        'other' => 1,
                    ],
                    'age' => [
                        '18.0' => 1,
                        '22.0' => 1,
                        '29.0' => 1,
                        '32.0' => 1,
                        '40.0' => 1,
                        '58.0' => 1,
                        '96.0' => 1,
                    ],
                    'departments' => [
                        'Backoffice' => 2,
                        'Development' => 2,
                        'Engineering' => 2,
                        'Facility-Management' => 1,
                        'Project Management' => 1,
                    ],
                    'recentPerformanceScores' => [
                        '2.8' => 1,
                        '3.8' => 1,
                        '3.9' => 1,
                        '4.0' => 1,
                        '4.1' => 2,
                        '4.2' => 1,
                        '4.5' => 1,
                        '4.6' => 1,
                        '4.7' => 1,
                    ],
                ],
                'facetStats' => [
                    'age' => [
                        'min' => 18.0,
                        'max' => 96.0,
                    ],
                    'recentPerformanceScores' => [
                        'min' => 2.8,
                        'max' => 4.7,
                    ],
                ],
            ],
        ];

        yield 'Getting all types of facets with filter' => [
            '',
            "departments = 'Backoffice'",
            ['gender', 'age', 'departments', 'recentPerformanceScores'],
            [
                'hits' => [
                    [
                        'firstname' => 'Huckleberry',
                    ],
                    [
                        'firstname' => 'Uta',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 2,
                'facetDistribution' => [
                    'gender' => [
                        'female' => 1,
                        'male' => 1,
                    ],
                    'age' => [
                        '18.0' => 1,
                        '29.0' => 1,
                    ],
                    'departments' => [
                        'Backoffice' => 2,
                        'Development' => 1,
                    ],
                    'recentPerformanceScores' => [
                        '2.8' => 1,
                        '3.9' => 1,
                        '4.1' => 1,
                    ],
                ],
                'facetStats' => [
                    'age' => [
                        'min' => 18.0,
                        'max' => 29.0,
                    ],
                    'recentPerformanceScores' => [
                        'min' => 2.8,
                        'max' => 4.1,
                    ],
                ],
            ],
        ];

        yield 'Test results containing empty facets' => [
            'Marko Gerste',
            '',
            ['gender', 'age', 'departments', 'recentPerformanceScores'],
            [
                'hits' => [
                    [
                        'firstname' => 'Marko',
                    ],
                ],
                'query' => 'Marko Gerste',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
                'facetDistribution' => [
                    'gender' => [],
                    'age' => [
                        '22.0' => 1,
                    ],
                    'departments' => [],
                    'recentPerformanceScores' => [],
                ],
                'facetStats' => [
                    'age' => [
                        'min' => 22.0,
                        'max' => 22.0,
                    ],
                    'recentPerformanceScores' => [],
                ],
            ],
        ];
    }

    public static function sortOnMultiAttributesWithMinAndMaxModifiers(): \Generator
    {
        yield 'Test MIN aggregate without filters (ASC)' => [
            'min(dates):asc',
            '',
            [
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
                [
                    'id' => 3,
                    'name' => 'Event C',
                ],
            ],
        ];
        yield 'Test MIN aggregate without filters (DESC)' => [
            'min(dates):desc',
            '',
            [
                [
                    'id' => 3,
                    'name' => 'Event C',
                ],
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
            ],
        ];

        yield 'Test MAX aggregate without filters (ASC)' => [
            'max(dates):asc',
            '',
            [
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
                [
                    'id' => 3,
                    'name' => 'Event C',
                ],
            ],
        ];

        yield 'Test MIN aggregate with filters (ASC)' => [
            'min(dates):asc',
            'dates BETWEEN 2 AND 6 AND ratings BETWEEN 2 AND 6', // the ratings does not really filter because we use the same values as in "dates", just here to spot query errors
            [
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
            ],
        ];

        yield 'Test MIN aggregate with filters (DESC)' => [
            'min(dates):desc',
            'dates BETWEEN 2 AND 6 AND ratings BETWEEN 2 AND 6', // the ratings does not really filter because we use the same values as in "dates", just here to spot query errors
            [
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
            ],
        ];

        yield 'Test MAX aggregate with filters (ASC)' => [
            'max(dates):asc',
            'dates BETWEEN 2 AND 6 AND ratings BETWEEN 2 AND 6', // the ratings does not really filter because we use the same values as in "dates", just here to spot query errors
            [
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
            ],
        ];

        yield 'Test MAX aggregate with filters (DESC)' => [
            'max(dates):desc',
            'dates BETWEEN 2 AND 6 AND ratings BETWEEN 2 AND 6', // the ratings does not really filter because we use the same values as in "dates", just here to spot query errors
            [
                [
                    'id' => 1,
                    'name' => 'Event A',
                ],
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
            ],
        ];

        yield 'Test aggregate with combined filters' => [
            'max(dates):desc',
            '(dates BETWEEN 2 AND 6 OR ratings BETWEEN 2 AND 6) AND price > 25',
            [
                [
                    'id' => 2,
                    'name' => 'Event B',
                ],
            ],
        ];
    }

    public static function sortWithNullAndNonExistingValueProvider(): \Generator
    {
        yield 'ASC' => [
            ['rating:asc', 'name:asc'],
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
            ->withSort(['firstname:asc']);

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

    public function testDamerauLevensthein(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title']);

        $searchParameters = SearchParameters::create()
            ->withQuery('convesre') // With Levenshtein this would be a total cost of 3, with Damerau-Levenshtein, just 2
            ->withAttributesToRetrieve(['id', 'title'])
            ->withAttributesToHighlight(['title']);

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 42,
            'title' => 'These are my Converse Chucks!',
        ]);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 42,
                    'title' => 'These are my Converse Chucks!',
                    '_formatted' => [
                        'id' => 42,
                        'title' => 'These are my <em>Converse</em> Chucks!',
                    ],
                ],
            ],
            'query' => 'convesre',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    public function testDisplayedAttributes(): void
    {
        $configuration = Configuration::create()
            ->withDisplayedAttributes(['id', 'title']);
        $loupe = $this->setupLoupeWithMoviesFixture($configuration);

        $searchParameters = SearchParameters::create()
            ->withQuery('four')
            ->withSort(['title:asc']);

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

    public function testDistinct(): void
    {
        $loupe = $this->setupLoupeWitProductsFixture();

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['sku', 'product_id'])
            ->withQuery('ihpone')
            ->withDistinct('product_id')
            ->withSort(['name:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'sku' => 'IPH15-RD-128',
                    'product_id' => 1001,
                ],
            ],
            'query' => 'ihpone',
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
            ->withSort(['firstname:asc']);

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
            ->withSort(['firstname:asc']);

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
            ->withFilterableAttributes(['title', 'published']);

        $document = [
            'id' => 42,
            'title' => "^The 17\" O'Conner && O`Series \n OR a || 1%2 1~2 1*2 \r\n book? \r \twhat \\ text: }{ )( ][ - + // \n\r ok? end$",
            'published' => true,
        ];

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument($document);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'title', 'published'])
            ->withFilter('title = ' . SearchParameters::escapeFilterValue($document['title']));

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

    public function testExactnessRelevanceScoring(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withSortableAttributes(['content'])
            ->withLanguages(['en'])
            ->withRankingRules(['exactness']) // Only match on exactness to isolate this test case
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            [
                'id' => 1,
                'content' => 'The administrative assistant managed the files.',
            ],
            [
                'id' => 2,
                'content' => 'The administrator organized the new files efficiently.',
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('administrative files')
            ->withAttributesToRetrieve(['id', 'content'])
            ->withShowRankingScore(true);

        // Both documents would weigh exactly the same because both "administrative" and "administrator" get stemmed
        // for "administr". Also, the terms are exactly the same distance apart. Hence, we test the exactness feature here.
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'content' => 'The administrative assistant managed the files.',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'content' => 'The administrator organized the new files efficiently.',
                    '_rankingScore' => 0.5,
                ],
            ],
            'query' => 'administrative files',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);
    }

    public function testFilteringAndSortingForIdentifier(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['id'])
            ->withSortableAttributes(['id']);

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
            ->withFilter('id IN (42, 12)');

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
            ->withSearchableAttributes(['title']);

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
            ->withFilter(\sprintf(
                '_geoBoundingBox(location, %s, %s, %s, %s)',
                $dublin['lat'],
                $athen['lng'],
                $athen['lat'],
                $dublin['lng'],
            ));

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
            ->withSortableAttributes(['location', 'rating']);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'restaurants');

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'location'])
            ->withFilter('_geoRadius(location, 45.472735, 9.184019, 2000)')
            ->withSort(['_geoPoint(location, 45.472735, 9.184019):asc']);

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
            ->withAttributesToRetrieve(['*', '_geoDistance(location)']) // Test should also work with *
            ->withSort(['_geoPoint(location, 48.8561446,2.2978204):asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'name' => 'Bouillon Pigalle',
                    'address' => '22 Bd de Clichy, 75018 Paris, France',
                    'type' => 'french',
                    'rating' => 8,
                    'location' => [
                        'lat' => 48.8826517,
                        'lng' => 2.3352748,
                    ],
                    '_geoDistance(location)' => 4024,
                ],
                [
                    'id' => 3,
                    'name' => 'Artico Gelateria Tradizionale',
                    'address' => 'Via Dogana, 1, 20123 Milan, Italy',
                    'type' => 'ice cream',
                    'rating' => 10,
                    'location' => [
                        'lat' => 45.4632046,
                        'lng' => 9.1719421,
                    ],
                    '_geoDistance(location)' => 641824,
                ],
                [
                    'id' => 1,
                    'name' => "Nàpiz' Milano",
                    'address' => 'Viale Vittorio Veneto, 30, 20124, Milan, Italy',
                    'type' => 'pizza',
                    'rating' => 9,
                    'location' => [
                        'lat' => 45.4777599,
                        'lng' => 9.1967508,
                    ],
                    '_geoDistance(location)' => 642336,
                ],
                [
                    'id' => 4,
                    'name' => 'Revire Brasas Bravas',
                    'address' => 'Av. Corrientes 1124, C1043 Cdad. Autónoma de Buenos Aires, Argentina',
                    'type' => 'steak',
                    'rating' => 10,
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
            ->withSearchableAttributes(['title']);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        $searchParameters = SearchParameters::create()
            ->withFilter('_geoRadius(location, 52.52, 13.405, ' . $distance . ')' /* Berlin */)
            ->withAttributesToRetrieve(['id', 'title', 'location']);

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
            ->withSearchableAttributes(['title']);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        $searchParameters = SearchParameters::create()
            ->withFilter('_geoRadius(location, 52.52, 13.405, 1000000)' /* Berlin */)
            ->withAttributesToRetrieve(['id', 'title', 'location', '_geoDistance(location)']);

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

    public function testIgnoresTooLongQuery(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('This is a very long query that should be shortened because it is just way too long')
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc']);

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
            ->withSort(['firstname:asc']);

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
    #[DataProvider('lowerAndGreaterThanAndBetweenFilters')]
    public function testLowerAndGreaterAndBetweenThanFilters(string $filter, array $expectedHits): void
    {
        $configuration = Configuration::create();

        $configuration = $configuration
            ->withFilterableAttributes(['rating', 'dates'])
            ->withSortableAttributes(['name'])
            ->withSearchableAttributes(['name']);

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocuments([
            [
                'id' => 1,
                'name' => 'Star Wars',
                'rating' => 2.5,
                'dates' => [
                    (new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2025-02-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2025-03-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
            [
                'id' => 2,
                'name' => 'Indiana Jones',
                'rating' => 3.5,
                'dates' => [
                    (new \DateTimeImmutable('2025-02-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2025-02-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
            [
                'id' => 3,
                'name' => 'Jurassic Park',
                'rating' => 4,
                'dates' => [
                    (new \DateTimeImmutable('2025-03-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2025-04-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
            [
                'id' => 6,
                'name' => 'Gladiator',
                'rating' => 5,
                'dates' => [
                    (new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                    (new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
            [
                'id' => 4,
                'name' => 'Interstellar',
                'rating' => null,
                'dates' => [],
            ],
            [
                'id' => 5,
                'name' => 'Back to the future',
                'rating' => 0,
                'dates' => [],
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'rating', 'dates'])
            ->withFilter($filter)
            ->withSort(['name:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedHits,
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => \count($expectedHits),
        ]);
    }

    public function testMaxHits(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withMaxTotalHits(100);

        $loupe = $this->createLoupe($configuration);
        $documents = [];

        foreach (range(1, 100) as $id) {
            $documents[] = [
                'id' => $id,
                'content' => 'dog',
            ];
        }
        foreach (range(101, 200) as $id) {
            $documents[] = [
                'id' => $id,
                'content' => 'cat',
            ];
        }
        foreach (range(201, 300) as $id) {
            $documents[] = [
                'id' => $id,
                'content' => 'bird',
            ];
        }
        $loupe->addDocuments($documents);

        $searchParameters = SearchParameters::create()
            ->withQuery('dog cat bird')
            ->withAttributesToRetrieve(['id'])
            ->withHitsPerPage(50);

        $results = $loupe->search($searchParameters)->toArray();
        unset($results['processingTimeMs']);
        unset($results['hits']);

        $this->assertSame([
            'query' => 'dog cat bird',
            'hitsPerPage' => 50,
            'page' => 1,
            'totalPages' => 2,
            'totalHits' => 100,
        ], $results);
    }

    public function testNegatedComplexSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParametersWithoutNegation = SearchParameters::create()
            ->withQuery('mother -boy -"depressed suburban father" father')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

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
            'query' => 'mother -boy -"depressed suburban father" father',
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
            ->withSort(['title:asc']);

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
            ->withSort(['title:asc']);

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
            ->withSort(['title:asc']);

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
            ->withSort(['title:asc']);

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
            ->withSort(['firstname:asc']);

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
            ->withTypoTolerance(TypoTolerance::create()->disable());

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        // Test with regular Great Barrier Reef search should list Finding Nemo first because of relevance
        // sorting, but it should also include other movies with the term "great".
        $searchParameters = SearchParameters::create()
            ->withQuery('Great Barrier Reef')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 12,
                    'title' => 'Finding Nemo',
                ],
                [
                    'id' => 13,
                    'title' => 'Forrest Gump',
                ],
            ],
            'query' => 'Great Barrier Reef',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 2,
        ]);

        // Now let's search for "Great Barrier Reef" which should return "Finding Nemo" only.
        $searchParameters = $searchParameters->withQuery('"Great Barrier Reef"');

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 12,
                    'title' => 'Finding Nemo',
                ],
            ],
            'query' => '"Great Barrier Reef"',
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
            ->withSearchableAttributes(['title', 'overview']);

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
            ->withAttributesToRetrieve(['id', 'title']);

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
    public function testPrefixSearch(string $query, array $expectedResults, int|null $minTokenLengthForPrefixSearch = null): void
    {
        $configuration = Configuration::create();

        if ($minTokenLengthForPrefixSearch !== null) {
            $configuration = $configuration->withMinTokenLengthForPrefixSearch($minTokenLengthForPrefixSearch);
        }

        $loupe = $this->setupLoupeWithDepartmentsFixture($configuration);

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedResults,
            'query' => $query,
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => \count($expectedResults) === 0 ? 0 : 1,
            'totalHits' => \count($expectedResults),
        ]);
    }

    public function testPrefixSearchAndFormattingWithTypoSearchEnabled(): void
    {
        $typoTolerance = TypoTolerance::create()->withEnabledForPrefixSearch(true);
        $configuration = Configuration::create()->withTypoTolerance($typoTolerance);
        $loupe = $this->setupLoupeWithMoviesFixture($configuration);

        $searchParameters = SearchParameters::create()
            ->withQuery('assat')
            ->withAttributesToRetrieve(['id', 'title', 'overview'])
            ->withSort(['title:asc'])
            ->withAttributesToHighlight(['overview']);

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
            ->withSort(['title:asc']);

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

    public function testQueryCombinedWithFilter(): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('Aelxander')
            ->withFilter("colors IN ('Blue')")
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [[
                'id' => 3,
                'firstname' => 'Alexander',
                'lastname' => 'Abendroth',
            ],
            ],
            'query' => 'Aelxander',
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
            ->withHitsPerPage(1);

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
            ->withSortableAttributes(['content']);

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
            ->withShowRankingScore(true);

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
                    '_rankingScore' => 0.92028,
                ],
                [
                    'id' => 2,
                    'content' => 'The unexamined life is not worth living. Life is life.',
                    '_rankingScore' => 0.77641,
                ],
                [
                    'id' => 3,
                    'content' => 'Never stop learning',
                    '_rankingScore' => 0.77641,
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
                    '_rankingScore' => 0.92028,
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
            ->withSortableAttributes(['content']);

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
            ->withShowRankingScore(true);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 4,
                    'content' => 'Book title: life learning',
                    '_rankingScore' => 0.85094,
                ],
                [
                    'id' => 1,
                    'content' => 'The game of life is a game of everlasting learning',
                    '_rankingScore' => 0.77121,
                ],
                [
                    'id' => 2,
                    'content' => 'The unexamined life is not worth living. Life is life.',
                    '_rankingScore' => 0.70187,
                ],
                [
                    'id' => 3,
                    'content' => 'Never stop learning',
                    '_rankingScore' => 0.70187,
                ],
            ],
            'query' => 'foobar life learning',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 4,
        ]);
    }

    public function testRelevanceAndRankingScoreWithAttributeWeights(): void
    {
        $documents = [
            [
                'id' => 1,
                'title' => 'Game of life',
                'content' => 'A thing with everlasting learning',
            ],
            [
                'id' => 2,
                'title' => 'Everlasting learning',
                'content' => 'The unexamined game of life',
            ],
            [
                'id' => 3,
                'title' => 'Learning to game',
                'content' => 'What life taught me about learning',
            ],
        ];

        $searchParameters = SearchParameters::create()
            ->withQuery('game of life')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withShowRankingScore(true);

        $configurationWithoutAttributes = Configuration::create()
            ->withSortableAttributes(['title', 'content']);

        $loupe = $this->createLoupe($configurationWithoutAttributes);
        $loupe->addDocuments($documents);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'title' => 'Game of life',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'title' => 'Everlasting learning',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 3,
                    'title' => 'Learning to game',
                    '_rankingScore' => 0.76259,
                ],
            ],
            'query' => 'game of life',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        $configurationWithAttributes = Configuration::create()
            ->withSearchableAttributes(['title', 'content'])
            ->withSortableAttributes(['title', 'content']);

        $loupe = $this->createLoupe($configurationWithAttributes);
        $loupe->addDocuments($documents);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'title' => 'Game of life',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'title' => 'Everlasting learning',
                    '_rankingScore' => 0.93964,
                ],
                [
                    'id' => 3,
                    'title' => 'Learning to game',
                    '_rankingScore' => 0.73785,
                ],
            ],
            'query' => 'game of life',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);
    }

    public function testRelevanceWithCustomRankingRules(): void
    {
        $documents = [
            [
                'id' => 1,
                'title' => 'Lorem ipsum',
                'content' => 'dolor sit amet',
            ],
            [
                'id' => 2,
                'title' => 'Lorem dolor sit amet',
                'content' => 'Ipsum',
            ],
            [
                'id' => 3,
                'title' => 'Dolor',
                'content' => 'Lorem sit amet',
            ],
        ];

        $searchParameters = SearchParameters::create()
            ->withQuery('lorem ipsum')
            ->withShowRankingScore(true);

        $configurationWithDefaultRules = Configuration::create()
            ->withSearchableAttributes(['title', 'content']);

        $loupe = $this->createLoupe($configurationWithDefaultRules);
        $loupe->addDocuments($documents);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'title' => 'Lorem ipsum',
                    'content' => 'dolor sit amet',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'title' => 'Lorem dolor sit amet',
                    'content' => 'Ipsum',
                    '_rankingScore' => 0.88691,
                ],
                [
                    'id' => 3,
                    'title' => 'Dolor',
                    'content' => 'Lorem sit amet',
                    '_rankingScore' => 0.75167,
                ],
            ],
            'query' => 'lorem ipsum',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        $configurationWithWordsOnly = Configuration::create()
            ->withSearchableAttributes(['title', 'content'])
            ->withRankingRules(['words']);

        $loupe = $this->createLoupe($configurationWithWordsOnly);
        $loupe->addDocuments($documents);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'title' => 'Lorem ipsum',
                    'content' => 'dolor sit amet',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'title' => 'Lorem dolor sit amet',
                    'content' => 'Ipsum',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 3,
                    'title' => 'Dolor',
                    'content' => 'Lorem sit amet',
                    '_rankingScore' => 0.5,
                ],
            ],
            'query' => 'lorem ipsum',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);

        $configurationWithAttributesOnly = Configuration::create()
            ->withSearchableAttributes(['title', 'content'])
            ->withRankingRules(['attribute']);

        $loupe = $this->createLoupe($configurationWithAttributesOnly);
        $loupe->addDocuments($documents);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 1,
                    'title' => 'Lorem ipsum',
                    'content' => 'dolor sit amet',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => 2,
                    'title' => 'Lorem dolor sit amet',
                    'content' => 'Ipsum',
                    '_rankingScore' => 0.8,
                ],
                [
                    'id' => 3,
                    'title' => 'Dolor',
                    'content' => 'Lorem sit amet',
                    '_rankingScore' => 0.8,
                ],
            ],
            'query' => 'lorem ipsum',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 3,
        ]);
    }

    public function testSearchingForAQueryThatMatchesWayTooManyDocumentsDoesNotTakeForeverAndAlsoStillReturnsTheMostRelevantDocument(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withMaxTotalHits(800)
        ;

        $loupe = $this->createLoupe($configuration);
        $documents = [];

        foreach (range(1, 2000) as $id) {
            $documents[] = [
                'id' => str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'content' => 'dog',
            ];
        }

        $documents[] = [
            'id' => '9999',
            'content' => 'dog sled',
        ];

        $loupe->addDocuments($documents);

        $searchParameters = SearchParameters::create()
            ->withQuery('dog sled')
            ->withAttributesToRetrieve(['id', 'content'])
            ->withShowRankingScore(true)
            ->withHitsPerPage(4)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => '9999',
                    'content' => 'dog sled',
                    '_rankingScore' => 1.0,
                ],
                [
                    'id' => '0001',
                    'content' => 'dog',
                    '_rankingScore' => 0.77641,
                ],
                [
                    'id' => '0002',
                    'content' => 'dog',
                    '_rankingScore' => 0.77641,
                ],
                [
                    'id' => '0003',
                    'content' => 'dog',
                    '_rankingScore' => 0.77641,
                ],
            ],
            'query' => 'dog sled',
            'hitsPerPage' => 4,
            'page' => 1,
            'totalPages' => 200,
            'totalHits' => 800, // Max total hits
        ]);
    }

    public function testSearchingForNumericArrayType(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['months']);

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 42,
            'months' => [04, 05],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'months'])
            ->withFilter('months IN (04)');

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

    public function testSearchReturnsEmptyResultOnDatabaseSchemaUpdates(): void
    {
        // Copy the fixture to a temporary directory to prevent other files being created within our git repository
        $tempDir = $this->createTemporaryDirectory();
        (new Filesystem())->copy(Util::fixturesPath('OldDatabaseSchema/v012/loupe.db'), $tempDir . '/loupe.db');
        $loupe = $this->setupLoupeWithDepartments(null, $tempDir);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'firstname'])
            ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND gender = 'female'")
            ->withSort(['firstname:asc']);

        // Searching now causes exceptions because the schema of the v012 database is wrong
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
    }

    public function testSearchWithAttributesToSearchOn(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('four')
            ->withAttributesToSearchOn(['title'])
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

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

    /**
     * @param array<string,mixed> $expectedHit
     */
    #[DataProvider('searchWithDecompositionProvider')]
    public function testSearchWithDecomposition(string $text, string $query, array $expectedHit): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['text'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 42,
            'text' => $text,
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToHighlight(['text'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [$expectedHit],
            'query' => $query,
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);
    }

    /**
     * @param array<string> $facets
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('searchWithFacetsProvider')]
    public function testSearchWithFacets(string $query, string $filter, array $facets, array $expectedResults): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withFilter($filter)
            ->withFacets($facets)
            ->withAttributesToRetrieve(['firstname'])
            ->withSort(['firstname:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }

    public function testSimpleSearch(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('four')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

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
            ->withSort(['firstname:asc']);

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
                    'firstname' => 'Thomas',
                ],
                [
                    'firstname' => 'Uta',
                ],
            ],
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 7,
        ]);

        $searchParameters = $searchParameters->withSort(['firstname:desc']);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'firstname' => 'Uta',
                ],
                [
                    'firstname' => 'Thomas',
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
            'totalHits' => 7,
        ]);
    }

    /**
     * @param array<array<string,mixed>> $expectedHits
     */
    #[DataProvider('sortOnMultiAttributesWithMinAndMaxModifiers')]
    public function testSortOnMultiAttributesWithMinAndMaxModifiers(string $sort, string $filter, array $expectedHits): void
    {
        $configuration = Configuration::create();

        $configuration = $configuration
            ->withFilterableAttributes(['dates', 'ratings', 'price'])
            ->withSortableAttributes(['dates', 'ratings']);

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocuments([
            [
                'id' => 1,
                'name' => 'Event A',
                'dates' => [2, 3, 4, 5, 6],
                'ratings' => [2, 3, 4, 5, 6],
                'price' => 20,
            ],
            [
                'id' => 2,
                'name' => 'Event B',
                'dates' => [1, 3, 4, 5],
                'ratings' => [1, 3, 4, 5],
                'price' => 30,
            ],
            [
                'id' => 3,
                'name' => 'Event C',
                'dates' => [7, 8],
                'ratings' => [7, 8],
                'price' => 40,
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name'])
            ->withFilter($filter)
            ->withSort([$sort]);

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
     * @param array<string> $sort
     * @param array<array<string,mixed>> $expectedHits
     */
    #[DataProvider('sortWithNullAndNonExistingValueProvider')]
    public function testSortWithNullAndNonExistingValue(array $sort, array $expectedHits): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['rating'])
            ->withSortableAttributes(['name', 'rating'])
            ->withSearchableAttributes(['name']);

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
                'rating' => null,
            ],
        ]);

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['id', 'name', 'rating'])
            ->withSort($sort);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedHits,
            'query' => '',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => \count($expectedHits),
        ]);
    }

    public function testStemmingAndDecompositionDoesNotHappenForQueries(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withLanguages(['de'])
        ;
        $loupe = $this->createLoupe($configuration);
        $loupe->addDocument([
            'id' => 42,
            'content' => 'Ich bin ein Schiff',
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('Dampfschiff')
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => 'Dampfschiff',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
    }

    public function testStopWordSearch(): void
    {
        $searchParameters = SearchParameters::create()
            ->withQuery('young glaciologist')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

        $configurationWithoutStopWords = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
            ->withTypoTolerance(TypoTolerance::create()->disable());

        $loupe = $this->createLoupe($configurationWithoutStopWords);
        $this->indexFixture($loupe, 'movies');

        // Should return all movies with the term "young" (OR matching)
        $this->searchAndAssertResults($loupe, $searchParameters, [
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

        $configurationWithStopWords = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withStopWords(['young']);

        $loupe = $this->createLoupe($configurationWithStopWords);
        $this->indexFixture($loupe, 'movies');

        // Should only return movies with the term "glaciologist" since "young" is a stop word
        $this->searchAndAssertResults($loupe, $searchParameters, [
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

        $loupe = $this->createLoupe($configurationWithStopWords);
        $this->indexFixture($loupe, 'movies');

        // Test stop words are ignored for ordering by relevance
        $searchParameters = $searchParameters->withSort(['_relevance:desc']);
        $this->searchAndAssertResults($loupe, $searchParameters, [
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
            ->withSearchableAttributes(['firstname', 'lastname']);

        $configuration = $configuration->withTypoTolerance($typoTolerance);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'departments');

        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToRetrieve(['id', 'firstname', 'lastname'])
            ->withSort(['firstname:asc']);

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }

    public function testUpdatedDocumentSearch(): void
    {
        $configuration = Configuration::create()
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title'])
            ->withTypoTolerance(TypoTolerance::create()->disable());

        $searchParameters = SearchParameters::create()
            ->withQuery('vienna')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withSort(['title:asc']);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'locations');

        // Should return Vienna
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => '3',
                    'title' => 'Vienna',
                ],
            ],
            'query' => 'vienna',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 1,
            'totalHits' => 1,
        ]);

        $loupe->addDocument([
            'id' => '3',
            'title' => 'Munich',
        ]);

        // Should not return old Vienna document
        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [],
            'query' => 'vienna',
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => 0,
            'totalHits' => 0,
        ]);
    }

    /**
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('negatedQueryProvider')]
    public function testWithNegation(string $query, array $expectedResults): void
    {
        $loupe = $this->setupLoupeWithDepartmentsFixture();

        $searchParameters = SearchParameters::create()
            ->withAttributesToRetrieve(['firstname', 'lastname'])
            ->withSort(['firstname:asc'])
            ->withQuery($query);

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => $expectedResults,
            'query' => $query,
            'hitsPerPage' => 20,
            'page' => 1,
            'totalPages' => \count($expectedResults) === 0 ? 0 : 1,
            'totalHits' => \count($expectedResults),
        ]);
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

        yield 'Test no match with the default thresholds (Gukcleberry -> Huckleberry -> distance of 3) - match with threshold to 3 thanks to Damerau-Levenshtein' => [
            TypoTolerance::create()->withTypoThresholds([
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
}
