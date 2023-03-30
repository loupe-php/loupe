<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use Terminal42\Loupe\Loupe;

class SearchTest extends AbstractFunctionalTest
{
    public function testSearch(): void
    {
        $loupe = $this->setupSharedLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
            'searchableAttributes' => ['firstname', 'lastname'],
        ], 'departments');

        $this->runTests('Search', $loupe);
    }

    public function testSearchingWithGeo(): void
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
