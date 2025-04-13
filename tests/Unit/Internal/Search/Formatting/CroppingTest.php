<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Search\Formatting;

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
        yield 'Cropping with too little text and no change' => [
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
                            'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
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

        yield 'Cropping without highlighting' => [
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
                            'overview' => 'Selma, a Czech immigrant on the verge of blindness, struggles…expensive operation. When life gets too difficult, Selma learns to cope through her love of musicals, escaping…',
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
    }

    /**
     * @param array<string> $searchableAttributes
     * @param array<string> $attributesToCrop
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
            ->withAttributesToCrop($attributesToCrop, $cropMarker, $cropLength)
            ->withAttributesToRetrieve(['id', 'title', 'overview', 'genres'])
            ->withSort(['title:asc'])
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, $expectedResults);
    }
}
