<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

class PaginationTest extends TestCase
{
    use FunctionalTestTrait;

    public function testPaginationWithHitsPerPage(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('and')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withHitsPerPage(5)
            ->withOffset(100) // Should be ignored because "hitsPerPage" is set
            ->withLimit(100) // Should be ignored because "hitsPerPage" is set
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'title' => 'Ariel',
                ],
                [
                    'id' => 5,
                    'title' => 'Four Rooms',
                ],
                [
                    'id' => 6,
                    'title' => 'Judgment Night',
                ],
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
                [
                    'id' => 12,
                    'title' => 'Finding Nemo',
                ],
            ],
            'query' => 'and',
            'hitsPerPage' => 5,
            'page' => 1,
            'totalPages' => 3,
            'totalHits' => 14,
        ]);
    }

    public function testPaginationWithMultipleTerms(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('and or')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withHitsPerPage(1)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 15,
                    'title' => 'Citizen Kane',
                ],
            ],
            'query' => 'and or',
            'hitsPerPage' => 1,
            'page' => 1,
            'totalPages' => 14,
            'totalHits' => 14,
        ]);
    }

    public function testPaginationWithOffsetAndLimit(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('and')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withOffset(2)
            ->withLimit(2)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 6,
                    'title' => 'Judgment Night',
                ],
                [
                    'id' => 11,
                    'title' => 'Star Wars',
                ],
            ],
            'query' => 'and',
            'hitsPerPage' => 2,
            'page' => 2,
            'totalPages' => 7,
            'totalHits' => 14,
        ]);
    }

    public function testPaginationWithOneHitPerPage(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $searchParameters = SearchParameters::create()
            ->withQuery('and')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withHitsPerPage(1)
        ;

        $this->searchAndAssertResults($loupe, $searchParameters, [
            'hits' => [
                [
                    'id' => 2,
                    'title' => 'Ariel',
                ],
            ],
            'query' => 'and',
            'hitsPerPage' => 1,
            'page' => 1,
            'totalPages' => 14,
            'totalHits' => 14,
        ]);
    }
}
