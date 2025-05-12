<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

class BrowseTest extends TestCase
{
    use FunctionalTestTrait;

    public function testBrowse(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $browseParameters = BrowseParameters::create()
            ->withQuery('four')
            ->withAttributesToRetrieve(['id', 'title'])
        ;

        $this->browseAndAssertResults($loupe, $browseParameters, [
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

    public function testMaxTotalHitsDoesNotApplyToBrowseApi(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withMaxTotalHits(200)
        ;

        $loupe = $this->createLoupe($configuration);
        $documents = [];

        foreach (range(1, 500) as $id) {
            $documents[] = [
                'id' => str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'content' => 'dog',
            ];
        }
        $loupe->addDocuments($documents);

        $browseParameters = BrowseParameters::create()
            ->withQuery('dog sled')
            ->withAttributesToRetrieve(['id', 'content'])
            ->withHitsPerPage(4)
        ;

        $this->browseAndAssertResults($loupe, $browseParameters, [
            'hits' => [
                [
                    'id' => '0001',
                    'content' => 'dog',
                ],
                [
                    'id' => '0002',
                    'content' => 'dog',
                ],
                [
                    'id' => '0003',
                    'content' => 'dog',
                ],
                [
                    'id' => '0004',
                    'content' => 'dog',
                ],
            ],
            'query' => 'dog sled',
            'hitsPerPage' => 4,
            'page' => 1,
            'totalPages' => 125,
            'totalHits' => 500, // Max total hits must be ignored
        ]);
    }
}
