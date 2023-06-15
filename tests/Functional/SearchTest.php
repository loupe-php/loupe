<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use Terminal42\Loupe\Loupe;

class SearchTest extends AbstractFunctionalTest
{
    public function testRelevance(): void
    {
        $loupe = $this->setupSharedLoupe([
            'searchableAttributes' => ['content'],
            'sortableAttributes' => ['content'],
        ], 'relevance');

        $this->runTests('Relevance', $loupe);
    }

    public function testSearchingOnDepartments(): void
    {
        $loupe = $this->setupSharedLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
            'searchableAttributes' => ['firstname', 'lastname'],
        ], 'departments');

        $this->runTests('Departments', $loupe);
    }

    public function testSearchingOnMovies5000(): void
    {
        $loupe = $this->setupSharedLoupe([
            'searchableAttributes' => ['title', 'overview'],
            'filterableAttributes' => ['genres'],
            'sortableAttributes' => ['title'],
        ], 'movies_5000');

        $this->runTests('Movies5000', $loupe);
    }

    public function testSearchingOnMoviesShort(): void
    {
        $loupe = $this->setupSharedLoupe([
            'searchableAttributes' => ['title', 'overview'],
            'filterableAttributes' => ['genres'],
            'sortableAttributes' => ['title'],
        ], 'movies_short');

        $this->runTests('MoviesShort', $loupe);
    }

    public function testSearchingOnRestaurantsWithGeo(): void
    {
        $loupe = $this->setupSharedLoupe([
            'filterableAttributes' => ['_geo', 'type'],
            'sortableAttributes' => ['_geo', 'rating'],
        ], 'restaurants');

        $this->runTests('Geo', $loupe);
    }

    private function runTests(string $testFolder, Loupe $loupe): void
    {
        $sectionInfo = [
            'TEST' => false,
            'SEARCH' => true,
            'EXPECT' => true,
        ];

        foreach ($this->getTests(__DIR__ . '/Tests/' . $testFolder, $sectionInfo) as $testData) {
            $this->setName(sprintf('%s [%s] %s', __METHOD__, $testData['TEST_FILE'], $testData['TEST']));

            $results = $loupe->search($testData['SEARCH']);

            unset($results['processingTimeMs']);

            $this->assertSame($testData['EXPECT'], $results);
        }
    }
}
