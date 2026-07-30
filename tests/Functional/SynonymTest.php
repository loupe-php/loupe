<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;

final class SynonymTest extends TestCase
{
    use FunctionalTestTrait;
    use StorageFixturesTestTrait;

    public function testExactnessRanksLiteralMatchAboveSynonym(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'tv' => ['television'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'television set'],
            ['id' => 2, 'title' => 'tv set'],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('tv')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withShowRankingScore(true)
        ;

        $results = $loupe->search($searchParameters)->toArray();

        $this->assertSame([2, 1], array_column($results['hits'], 'id'));
        $this->assertGreaterThan($results['hits'][1]['_rankingScore'], $results['hits'][0]['_rankingScore']);
    }

    public function testHighlightingArrayAttribute(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title', 'tags'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'tv' => ['television'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'gadget', 'tags' => ['brand new television', 'electronics']],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('tv')
            ->withAttributesToRetrieve(['id', 'title', 'tags'])
            ->withAttributesToHighlight(['tags'])
        ;

        $results = $loupe->search($searchParameters)->toArray();

        $this->assertSame(
            ['brand new <em>television</em>', 'electronics'],
            $results['hits'][0]['_formatted']['tags'],
        );
    }

    public function testHighlightingStringAttribute(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'tv' => ['television'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'television set'],
        ]);

        $searchParameters = SearchParameters::create()
            ->withQuery('tv')
            ->withAttributesToRetrieve(['id', 'title'])
            ->withAttributesToHighlight(['title'])
            ->withShowMatchesPosition(true)
        ;

        $results = $loupe->search($searchParameters)->toArray();

        $this->assertSame('<em>television</em> set', $results['hits'][0]['_formatted']['title']);

        $this->assertSame(
            [['start' => 0, 'length' => 10 ]],
            $results['hits'][0]['_matchesPosition']['title'],
        );
    }

    public function testOneWayMatch(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'phone' => ['iphone'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'iphone 15 pro'],
            ['id' => 2, 'title' => 'android phone'],
        ]);

        // Searching the synonym key "phone" matches both the generic phone and the iphone reached via the synonym.
        $this->assertSame([1, 2], $this->searchIds($loupe, 'phone'));

        // Searching "iphone" stays specific: it only matches the iphone document, not generic phones.
        $this->assertSame([1], $this->searchIds($loupe, 'iphone'));
    }

    public function testReindexIsRequiredWhenSynonymsChange(): void
    {
        $dataDir = $this->createTemporaryDirectory();

        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSynonyms([
                'phone' => ['iphone'],
            ])
        ;

        $loupe = $this->createLoupe($configuration, $dataDir);
        $loupe->addDocument(['id' => 1, 'title' => 'iphone 15 pro']);

        $this->assertFalse($loupe->needsReindex());

        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSynonyms([
                'phone' => ['iphone', 'smartphone'],
            ])
        ;

        $loupe = $this->createLoupe($configuration, $dataDir);

        $this->assertTrue($loupe->needsReindex());
    }

    public function testTwoWayMatch(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'couch' => ['sofa'],
                'sofa' => ['couch'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'comfortable couch'],
            ['id' => 2, 'title' => 'leather sofa'],
        ]);

        $this->assertSame([1, 2], $this->searchIds($loupe, 'couch'));
        $this->assertSame([1, 2], $this->searchIds($loupe, 'sofa'));
    }

    public function testTypoOnQueryHitsSynonym(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withSynonyms([
                'television' => ['tv'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'tv set'],
        ]);

        $this->assertSame([1], $this->searchIds($loupe, 'televsion'));
    }

    public function testTypoToleranceDisabledStillMatchesSynonym(): void
    {
        $configuration = Configuration::create()
            ->withSearchableAttributes(['title'])
            ->withSortableAttributes(['title'])
            ->withTypoTolerance(TypoTolerance::disabled())
            ->withSynonyms([
                'phone' => ['iphone'],
            ])
        ;

        $loupe = $this->createLoupe($configuration);
        $loupe->addDocuments([
            ['id' => 1, 'title' => 'iphone 15 pro'],
            ['id' => 2, 'title' => 'android phone'],
        ]);

        $this->assertSame([1, 2], $this->searchIds($loupe, 'phone'));
        $this->assertSame([1], $this->searchIds($loupe, 'iphone'));
    }

    /**
     * @return array<int>
     */
    private function searchIds(Loupe $loupe, string $query): array
    {
        $searchParameters = SearchParameters::create()
            ->withQuery($query)
            ->withAttributesToRetrieve(['id'])
        ;

        $ids = array_column($loupe->search($searchParameters)->toArray()['hits'], 'id');
        sort($ids);

        return $ids;
    }
}
