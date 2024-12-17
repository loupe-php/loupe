<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Util;

trait FunctionalTestTrait
{
    protected function createLoupe(Configuration $configuration, string $dataDir = ''): Loupe
    {
        $factory = new LoupeFactory();

        if ($dataDir === '') {
            $loupe = $factory->createInMemory($configuration);
        } else {
            $loupe = $factory->create($dataDir, $configuration);
        }

        return $loupe;
    }

    protected function indexFixture(Loupe $loupe, string $indexFixture = ''): void
    {
        if ($indexFixture === '') {
            return;
        }

        $contents = file_get_contents(Util::fixturesPath('Data/' . $indexFixture . '.json'));

        if ($contents === false) {
            throw new \InvalidArgumentException(sprintf('Fixture "%s" does not exist.', $indexFixture));
        }

        $loupe->addDocuments(json_decode($contents, true));
    }

    /**
     * @param array<mixed> $expectedResults
     */
    protected function searchAndAssertResults(Loupe $loupe, SearchParameters $searchParameters, array $expectedResults): void
    {
        $results = $loupe->search($searchParameters)->toArray();
        unset($results['processingTimeMs']);
        unset($loupe);
        $this->assertSame($expectedResults, $results);
    }
}
