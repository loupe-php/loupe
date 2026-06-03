<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional\Formatting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CroppingTest extends TestCase
{
    use FunctionalTestTrait;

    public static function croppingProvider(): \Generator
    {
        yield 'Cropping with matches spread across the text' => [
            'assassin employer member vengeance',
            ['title', 'overview'],
            ['overview'],
            [],
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
                            'overview' => 'An assassin is shot by her ruthless employer, Bill…members of their assassination circle – but she lives…',
                            'genres' => ['Action', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'assassin employer member vengeance',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Cropping with multiple matches' => [
            'assassin',
            ['title', 'overview'],
            ['overview'],
            [],
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
                            'overview' => 'An assassin is shot by her ruthless employer, Bill…members of their assassination circle – but she lives…',
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

        yield 'Cropping at the beginning' => [
            'selma',
            ['title', 'overview'],
            ['overview'],
            [],
            [
                'hits' => [
                    [
                        'id' => 16,
                        'title' => 'Dancer in the Dark',
                        'overview' => 'Selma, a Czech immigrant on the verge of blindness, struggles to make ends meet for herself and her son, who has inherited the same genetic disorder and will suffer the same fate without an expensive operation. When life gets too difficult, Selma learns to cope through her love of musicals, escaping life\'s troubles - even if just for a moment - by dreaming up little numbers to the rhythmic beats of her surroundings.',
                        'genres' => ['Drama', 'Crime'],
                        '_formatted' => [
                            'id' => 16,
                            'title' => 'Dancer in the Dark',
                            'overview' => 'Selma, a Czech immigrant on the verge of blindness…life gets too difficult, Selma learns to cope through…',
                            'genres' => ['Drama', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'selma',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Cropping at the end' => [
            'surroundings',
            ['title', 'overview'],
            ['overview'],
            [],
            [
                'hits' => [
                    [
                        'id' => 16,
                        'title' => 'Dancer in the Dark',
                        'overview' => 'Selma, a Czech immigrant on the verge of blindness, struggles to make ends meet for herself and her son, who has inherited the same genetic disorder and will suffer the same fate without an expensive operation. When life gets too difficult, Selma learns to cope through her love of musicals, escaping life\'s troubles - even if just for a moment - by dreaming up little numbers to the rhythmic beats of her surroundings.',
                        'genres' => ['Drama', 'Crime'],
                        '_formatted' => [
                            'id' => 16,
                            'title' => 'Dancer in the Dark',
                            'overview' => '…numbers to the rhythmic beats of her surroundings.',
                            'genres' => ['Drama', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'surroundings',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        ];

        yield 'Cropping with custom crop length' => [
            'assassin',
            ['title', 'overview'],
            ['overview'],
            [],
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
                            'overview' => 'An assassin is shot by her…their assassination circle…',
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
            [],
            '<em>',
            '</em>',
            '…',
            25,
        ];

        yield 'Cropping with highlights' => [
            'assassin',
            ['title', 'overview'],
            [
                'overview' => 20,
            ],
            ['title', 'overview'],
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
                            'overview' => 'An <em>assassin</em> is shot by…their <em>assassination</em> circle…',
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

        yield 'Cropping limited to a single fragment' => [
            'assassin',
            ['title', 'overview'],
            ['overview'],
            [],
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
                            'overview' => 'An assassin is shot by her…',
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
            [],
            '<em>',
            '</em>',
            '…',
            25,
            1,
        ];

        yield 'Cropping with match prioritization' => [
            'selma musicals',
            ['title', 'overview'],
            ['overview'],
            [],
            [
                'hits' => [
                    [
                        'id' => 16,
                        'title' => 'Dancer in the Dark',
                        'overview' => 'Selma, a Czech immigrant on the verge of blindness, struggles to make ends meet for herself and her son, who has inherited the same genetic disorder and will suffer the same fate without an expensive operation. When life gets too difficult, Selma learns to cope through her love of musicals, escaping life\'s troubles - even if just for a moment - by dreaming up little numbers to the rhythmic beats of her surroundings.',
                        'genres' => ['Drama', 'Crime'],
                        '_formatted' => [
                            'id' => 16,
                            'title' => 'Dancer in the Dark',
                            'overview' => '…musicals, escaping life\'s…',
                            'genres' => ['Drama', 'Crime'],
                        ],
                    ],
                ],
                'query' => 'selma musicals',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            [],
            '<em>',
            '</em>',
            '…',
            25,
            1,
            true,
        ];
    }

    /**
     * @param array<string> $searchableAttributes
     * @param array<string>|array<string,int> $attributesToCrop
     * @param array<string> $attributesToHighlight
     * @param array<mixed> $expectedResults
     * @param array<string> $stopWords
     */
    #[DataProvider('croppingProvider')]
    public function testCropping(
        string $query,
        array $searchableAttributes,
        array $attributesToCrop,
        array $attributesToHighlight,
        array $expectedResults,
        array $stopWords = [],
        string $highlightStartTag = '<em>',
        string $highlightEndTag = '</em>',
        string $cropMarker = '…',
        int $cropLength = 50,
        int $cropMaxFragments = 5,
        bool $prioritizeMatches = false,
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
            ->withAttributesToCrop($attributesToCrop, $cropLength, $cropMarker, $cropMaxFragments, $prioritizeMatches)
            ->withAttributesToRetrieve(['id', 'title', 'overview', 'genres'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }

    public function testPrioritizeMatchesSelectsDensestFragmentsButKeepsDocumentOrder(): void
    {
        $loupe = $this->createLoupe(Configuration::create()->withSearchableAttributes(['text']));

        // Match density increases through the document: 1 term, then 2, then 3 (the densest is last).
        $filler = ' This part holds no query terms and only pushes the matches far enough apart to form their own separate crop windows. ';
        $loupe->addDocuments([
            [
                'id' => 1,
                'text' => 'In the opening the term alpha appears all by itself.'
                    . $filler . 'Midway through the document the terms alpha bravo appear side by side.'
                    . $filler . 'Near the very end alpha bravo charlie cluster tightly together.',
            ],
        ]);

        $search = static fn (bool $prioritize): SearchParameters => SearchParameters::create()
            ->withQuery('alpha bravo charlie')
            ->withAttributesToCrop(['text'], cropLength: 30, cropMaxFragments: 2, prioritizeMatches: $prioritize)
            ->withAttributesToRetrieve(['id', 'text']);

        $this->assertSame(
            '…opening the term alpha appears all by…the terms alpha bravo appear side…',
            $loupe->search($search(false))->toArray()['hits'][0]['_formatted']['text'],
            'Document order picks the two earliest windows, dropping the densest trailing one'
        );

        $this->assertSame(
            '…alpha bravo appear side by side.…end alpha bravo charlie cluster…',
            $loupe->search($search(true))->toArray()['hits'][0]['_formatted']['text'],
            'Prioritization picks the two densest windows (2 then 3 terms), so the best fragment is emitted last'
        );
    }
}
