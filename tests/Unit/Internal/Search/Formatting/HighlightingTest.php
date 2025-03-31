<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Formatting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HighlightingTest extends TestCase
{
    use FunctionalTestTrait;

    public static function highlightingProvider(): \Generator
    {
        yield 'Highlighting with all searchable fields but no highlightable attributes' => [
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
                                    'stopword' => false,
                                ],
                                [
                                    'start' => 79,
                                    'length' => 13,
                                    'stopword' => false,
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
            [],
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

        yield 'Highlight multiple matches across stop words' => [
            'racing to a boxing match',
            ['title', 'overview'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 6,
                        'title' => 'Judgment Night',
                        'overview' => 'While racing to a boxing match, Frank, Mike, John and Rey get more than they bargained for. A wrong turn lands them directly in the path of Fallon, a vicious, wise-cracking drug lord. After accidentally witnessing Fallon murder a disloyal henchman, the four become his unwilling prey in a savage game of cat & mouse as they are mercilessly stalked through the urban jungle in this taut suspense drama',
                        'genres' => ['Action', 'Thriller', 'Crime'],
                        '_formatted' => [
                            'id' => 6,
                            'title' => 'Judgment Night',
                            'overview' => 'While <em>racing to a boxing match</em>, Frank, Mike, John and Rey get more than they bargained for. A wrong turn lands them directly in the path of Fallon, a vicious, wise-cracking drug lord. After accidentally witnessing Fallon murder a disloyal henchman, the four become his unwilling prey in a savage game of cat & mouse as they are mercilessly stalked through the urban jungle in this taut suspense drama',
                            'genres' => ['Action', 'Thriller', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'racing to a boxing match',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            ['of', 'the', 'an', 'but', 'to', 'a'],
        ];

        yield 'Highlight literal match including stopwords' => [
            'Pirates of the Caribbean: The Curse of the Black Pearl',
            ['title'],
            ['title', 'overview'],
            false,
            [
                'hits' => [
                    [
                        'id' => 22,
                        'title' => 'Pirates of the Caribbean: The Curse of the Black Pearl',
                        'overview' => "Jack Sparrow, a freewheeling 18th-century pirate, quarrels with a rival pirate bent on pillaging Port Royal. When the governor's daughter is kidnapped, Sparrow decides to help the girl's love save her.",
                        'genres' => ['Adventure', 'Fantasy', 'Action'],
                        '_formatted' => [
                            'id' => 22,
                            'title' => '<em>Pirates of the Caribbean</em>: <em>The Curse of the Black Pearl</em>',
                            'overview' => "Jack Sparrow, a freewheeling 18th-century pirate, quarrels with a rival pirate bent on pillaging Port Royal. When the governor's daughter is kidnapped, Sparrow decides to help the girl's love save her.",
                            'genres' => ['Adventure', 'Fantasy', 'Action'],
                        ],
                    ],
                ],
                'query' => 'Pirates of the Caribbean: The Curse of the Black Pearl',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            ['of', 'the', 'an', 'but', 'to', 'a', 'back'],
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
    }

    /**
     * @param array<string> $searchableAttributes
     * @param array<string> $attributesToHighlight
     * @param array<string> $stopWords
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('highlightingProvider')]
    public function testHighlighting(
        string $query,
        array $searchableAttributes,
        array $attributesToHighlight,
        bool $showMatchesPosition,
        array $expectedResults,
        array $stopWords = [],
        string $highlightStartTag = '<em>',
        string $highlightEndTag = '</em>',
    ): void {
        $configuration = Configuration::create()
            ->withSearchableAttributes($searchableAttributes)
            ->withFilterableAttributes(['genres'])
            ->withSortableAttributes(['title'])
            ->withStopWords($stopWords)
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
}
