<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use Terminal42\Loupe\Configuration;
use Terminal42\Loupe\Loupe;
use Terminal42\Loupe\LoupeFactory;
use Terminal42\Loupe\SearchParameters;

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

    protected function searchAndAssertResults(Loupe $loupe, SearchParameters $searchParameters, array $expectedResults)
    {
        $results = $loupe->search($searchParameters);
        unset($results['processingTimeMs']);
        $this->assertSame($expectedResults, $results);
    }
}
