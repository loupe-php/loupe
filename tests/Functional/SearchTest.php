<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

class SearchTest extends AbstractFunctionalTest
{
    public function testSearchingWithFilters(): void
    {
        $loupe = $this->setupSharedLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
        ], 'filters');

        $sectionInfo = [
            'TEST' => false,
            'SEARCH' => true,
            'EXPECT' => true,
        ];

        foreach ($this->getTests(__DIR__ . '/Tests/Filters', $sectionInfo) as $testData) {
            $this->setName(sprintf('%s [%s] %s', __METHOD__, $testData['TEST_FILE'], $testData['TEST']));

            $results = $loupe->search($testData['SEARCH']);

            unset($results['processingTimeMs']);

            $this->assertSame($testData['EXPECT'], $results);
        }
    }
}
