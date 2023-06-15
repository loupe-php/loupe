<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Performance;

use Symfony\Component\Filesystem\Filesystem;
use Terminal42\Loupe\Tests\Functional\AbstractFunctionalTest;

class PerformanceTest extends AbstractFunctionalTest
{
    /**
     * Demonstrates the performance of searching through over 30.000 movies.
     */
    public function testPerformance(): void
    {
        $path = __DIR__ . '/../../var/performance-tests.db';
        $fs = new Filesystem();

        if (! $fs->exists($path)) {
            $fs->dumpFile($path, '');
        }

        $loupe = $this->createLoupe([
            'filterableAttributes' => ['genres', 'release_date'],
            'sortableAttributes' => ['title'],
        ], $path);

        if (isset($_SERVER['LOUPE_PERFORMANCE_TEST_SETUP'])) {
            $this->indexFixture($loupe, 'movies_full');
        } elseif ($loupe->countDocuments() === 0) {
            $this->fail(sprintf(
                'Run PHPUnit with LOUPE_PERFORMANCE_TEST_SETUP=1 to create the DB first (can take up to 15 minutes). Make sure to delete the "%s" again when you are done.',
                $path
            ));
        }

        $sectionInfo = [
            'TEST' => false,
            'SEARCH' => true,
            'EXPECT' => true,
        ];

        foreach ($this->getTests(__DIR__ . '/Tests', $sectionInfo) as $testData) {
            $this->setName(sprintf('%s [%s] %s', __METHOD__, $testData['TEST_FILE'], $testData['TEST']));

            $results = $loupe->search($testData['SEARCH']);

            // Assert all the tests run faster than 1 second
            $this->assertLessThanOrEqual(1000, $results['processingTimeMs']);

            unset($results['processingTimeMs']);

            $this->assertSame($testData['EXPECT'], $results);
        }
    }
}
