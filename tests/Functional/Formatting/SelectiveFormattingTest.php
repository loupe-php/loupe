<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional\Formatting;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use PHPUnit\Framework\TestCase;

final class SelectiveFormattingTest extends TestCase
{
    use FunctionalTestTrait;

    public function testHighlightingOnlyTitleLeavesMatchingBodyUnformatted(): void
    {
        $loupe = $this->createLoupeWithBody();

        $this->searchAndAssertResults(
            $loupe,
            SearchParameters::create()
                ->withQuery('beta')
                ->withAttributesToHighlight(['title']),
            [
                'hits' => [
                    [
                        'id' => 1,
                        'title' => 'Beta lorem',
                        'body' => 'beta beta beta',
                        '_formatted' => [
                            'id' => 1,
                            'title' => '<em>Beta</em> lorem',
                            'body' => 'beta beta beta',
                        ],
                    ],
                ],
                'query' => 'beta',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        );
    }

    public function testCroppingOnlyTitleLeavesMatchingBodyUnformatted(): void
    {
        $loupe = $this->createLoupeWithBody();

        $this->searchAndAssertResults(
            $loupe,
            SearchParameters::create()
                ->withQuery('beta')
                ->withAttributesToCrop(['title']),
            [
                'hits' => [
                    [
                        'id' => 1,
                        'title' => 'Beta lorem',
                        'body' => 'beta beta beta',
                        '_formatted' => [
                            'id' => 1,
                            'title' => 'Beta lorem',
                            'body' => 'beta beta beta',
                        ],
                    ],
                ],
                'query' => 'beta',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        );
    }

    public function testShowMatchesPositionStillReportsUnhighlightedAttributes(): void
    {
        $loupe = $this->createLoupeWithBody();

        $this->searchAndAssertResults(
            $loupe,
            SearchParameters::create()
                ->withQuery('beta')
                ->withAttributesToHighlight(['title'])
                ->withShowMatchesPosition(true),
            [
                'hits' => [
                    [
                        'id' => 1,
                        'title' => 'Beta lorem',
                        'body' => 'beta beta beta',
                        '_formatted' => [
                            'id' => 1,
                            'title' => '<em>Beta</em> lorem',
                            'body' => 'beta beta beta',
                        ],
                        '_matchesPosition' => [
                            'title' => [
                                [
                                    'start' => 0,
                                    'length' => 4,
                                ],
                            ],
                            'body' => [
                                [
                                    'start' => 0,
                                    'length' => 4,
                                ],
                                [
                                    'start' => 5,
                                    'length' => 4,
                                ],
                                [
                                    'start' => 10,
                                    'length' => 4,
                                ],
                            ],
                        ],
                    ],
                ],
                'query' => 'beta',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        );
    }

    public function testHighlightingANonRetrievedAttributeStillWorks(): void
    {
        $loupe = $this->createLoupeWithBody();

        $this->searchAndAssertResults(
            $loupe,
            SearchParameters::create()
                ->withQuery('beta')
                ->withAttributesToRetrieve(['id', 'title'])
                ->withAttributesToHighlight(['body']),
            [
                'hits' => [
                    [
                        'id' => 1,
                        'title' => 'Beta lorem',
                        '_formatted' => [
                            'id' => 1,
                            'title' => 'Beta lorem',
                            'body' => '<em>beta beta beta</em>',
                        ],
                    ],
                ],
                'query' => 'beta',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        );
    }

    public function testWildcardHighlightStillFormatsAllSearchableAttributes(): void
    {
        $loupe = $this->createLoupeWithBody();

        $this->searchAndAssertResults(
            $loupe,
            SearchParameters::create()
                ->withQuery('beta')
                ->withAttributesToHighlight(['*']),
            [
                'hits' => [
                    [
                        'id' => 1,
                        'title' => 'Beta lorem',
                        'body' => 'beta beta beta',
                        '_formatted' => [
                            'id' => 1,
                            'title' => '<em>Beta</em> lorem',
                            'body' => '<em>beta beta beta</em>',
                        ],
                    ],
                ],
                'query' => 'beta',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
        );
    }

    private function createLoupeWithBody(): Loupe
    {
        $loupe = $this->createLoupe(
            Configuration::create()
                ->withSearchableAttributes(['title', 'body'])
                ->withLanguages(['en']),
        );

        $loupe->addDocuments([
            ['id' => 1, 'title' => 'Beta lorem', 'body' => 'beta beta beta'],
        ]);

        return $loupe;
    }
}
