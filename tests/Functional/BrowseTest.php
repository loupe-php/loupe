<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use PHPUnit\Framework\TestCase;

final class BrowseTest extends TestCase
{
    use FunctionalTestTrait;

    public function testBrowse(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $browseParameters = BrowseParameters::create()
            ->withQuery('four')
            ->withAttributesToRetrieve(['id', 'title'])
        ;

        $this->browseAndAssertResults(
            $loupe,
            $browseParameters,
            [
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
            ],
        );
    }

    public function testBrowsePrimaryKeyOnly(): void
    {
        $loupe = $this->setupLoupeWithMoviesFixture();

        $this->browseAndAssertResults(
            $loupe,
            BrowseParameters::create()
                ->withAttributesToRetrieve(['id'])
                ->withHitsPerPage(3)
                ->withPage(2),
            [
                'hits' => [
                    ['id' => 11],
                    ['id' => 12],
                    ['id' => 13],
                ],
                'query' => '',
                'hitsPerPage' => 3,
                'page' => 2,
                'totalPages' => 7,
                'totalHits' => 19,
            ],
        );
    }

    public function testBrowsePrimaryKeyOnlyKeepsIdTypeAndGaps(): void
    {
        $configuration = Configuration::create()
            ->withPrimaryKey('sku')
            ->withFilterableAttributes(['stock'])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['sku' => '0042', 'stock' => 1],
            ['sku' => 'a-1', 'stock' => 2],
            ['sku' => '7', 'stock' => 3],
        ]);
        $loupe->deleteDocument('a-1');

        $result = $loupe->browse(BrowseParameters::create()->withAttributesToRetrieve(['sku']));
        $this->assertSame([['sku' => '0042'], ['sku' => '7']], $result->getHits());
        $this->assertSame(2, $result->getTotalHits());

        $result = $loupe->browse(
            BrowseParameters::create()->withAttributesToRetrieve(['sku'])->withFilter('stock > 1'),
        );
        $this->assertSame([['sku' => '7']], $result->getHits());
        $this->assertSame(1, $result->getTotalHits());
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

        $this->browseAndAssertResults(
            $loupe,
            $browseParameters,
            [
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
            ],
        );

        $this->browseAndAssertResults(
            $loupe,
            $browseParameters->withPage(51),
            [
                'hits' => [
                    [
                        'id' => '0201',
                        'content' => 'dog',
                    ],
                    [
                        'id' => '0202',
                        'content' => 'dog',
                    ],
                    [
                        'id' => '0203',
                        'content' => 'dog',
                    ],
                    [
                        'id' => '0204',
                        'content' => 'dog',
                    ],
                ],
                'query' => 'dog sled',
                'hitsPerPage' => 4,
                'page' => 51,
                'totalPages' => 125,
                'totalHits' => 500,
            ],
        );
    }
}
