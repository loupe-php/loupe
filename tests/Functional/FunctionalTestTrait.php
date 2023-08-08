<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;

trait FunctionalTestTrait
{
    protected function createLoupe(Configuration $configuration, string $dbPath = ''): Loupe
    {
        $factory = new LoupeFactory();

        if ($dbPath === '') {
            $loupe = $factory->createInMemory($configuration);
        } else {
            $loupe = $factory->create($dbPath, $configuration);
        }

        return $loupe;
    }

    protected function indexFixture(Loupe $loupe, string $indexFixture = ''): void
    {
        if ($indexFixture === '') {
            return;
        }

        $loupe->addDocuments(json_decode(file_get_contents(__DIR__ . '/IndexData/' . $indexFixture . '.json'), true));
    }

    /**
     * @param array<mixed> $expectedResults
     */
    protected function searchAndAssertResults(Loupe $loupe, SearchParameters $searchParameters, array $expectedResults): void
    {
        $results = $loupe->search($searchParameters)->toArray();
        unset($results['processingTimeMs']);
        $this->assertSame($expectedResults, $results);
    }
}
