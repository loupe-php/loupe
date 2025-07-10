<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional\Formatting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormattingTest extends TestCase
{
    use FunctionalTestTrait;

    public static function formattingProvider(): \Generator
    {
        yield 'Matches position only' => [
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

        yield 'Matches position of stopwords' => [
            'her assassin',
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
                                    'start' => 23,
                                    'length' => 3,
                                ],
                                [
                                    'start' => 79,
                                    'length' => 13,
                                ],
                                [
                                    'start' => 124,
                                    'length' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
                'query' => 'her assassin',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            ['her'],
        ];

        yield 'Highlighting with matches position and array fields' => [
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

    /**
     * @param array<string> $searchableAttributes
     * @param array<string> $attributesToHighlight
     * @param array<string> $stopWords
     * @param array<mixed> $expectedResults
     */
    #[DataProvider('formattingProvider')]
    public function testFormatting(
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
