<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Performance;

use Terminal42\Loupe\Tests\Functional\AbstractFunctionalTest;

class PerformanceTest extends AbstractFunctionalTest
{
    /**
     * Demonstrates the performance of searching through over 30.000 movies.
     */
    public function testPerformance(): void
    {
        $loupe = $this->setupSharedLoupe([
            'filterableAttributes' => ['genres', 'release_date'],
            'sortableAttributes' => ['title'],
        ], 'movies_full', $this->createTestDb('performance', false));

        $sectionInfo = [
            'TEST' => false,
            'SEARCH' => true,
            'EXPECT' => true,
            'EXPECT-MAX-PROCESSING-TIME' => false,
        ];

        foreach ($this->getTests(__DIR__ . '/Tests', $sectionInfo) as $testData) {
            $this->setName(sprintf('%s [%s] %s', __METHOD__, $testData['TEST_FILE'], $testData['TEST']));

            $results = $loupe->search($testData['SEARCH']);

            $this->assertLessThanOrEqual((int) $testData['EXPECT-MAX-PROCESSING-TIME'], $results['processingTimeMs']);

            unset($results['processingTimeMs']);

            $this->assertSame($testData['EXPECT'], $results);
        }
    }
}
