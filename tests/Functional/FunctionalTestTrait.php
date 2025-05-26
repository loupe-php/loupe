<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\Tests\Util;

trait FunctionalTestTrait
{
    /**
     * @param array<mixed> $expectedResults
     */
    protected function browseAndAssertResults(Loupe $loupe, BrowseParameters $browseParameters, array $expectedResults): void
    {
        $results = $loupe->browse($browseParameters)->toArray();

        // Browse results are never sorted, let's sort them manually by 'id' which is just required for those tests
        uasort($results['hits'], static function (array $hitA, array $hitB) {
            return $hitA['id'] <=> $hitB['id'];
        });

        unset($results['processingTimeMs']);
        unset($loupe);
        $this->assertSame($expectedResults, $results);
    }

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

    protected function setupLoupeWithDepartmentsFixture(Configuration $configuration = null, string $dataDir = ''): Loupe
    {
        if ($configuration === null) {
            $configuration = Configuration::create();
        }

        $configuration = $configuration
            ->withFilterableAttributes(['departments', 'gender', 'isActive', 'colors', 'age', 'recentPerformanceScores'])
            ->withSortableAttributes(['firstname'])
            ->withSearchableAttributes(['firstname', 'lastname']);

        $loupe = $this->createLoupe($configuration, $dataDir);
        $this->indexFixture($loupe, 'departments');

        return $loupe;
    }

    protected function setupLoupeWithMoviesFixture(Configuration $configuration = null): Loupe
    {
        if ($configuration === null) {
            $configuration = Configuration::create();
        }

        $configuration = $configuration
            ->withFilterableAttributes(['genres'])
            ->withSortableAttributes(['title'])
            ->withSearchableAttributes(['title', 'overview']);

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'movies');

        return $loupe;
    }

    protected function setupLoupeWitProductsFixture(Configuration $configuration = null, string $dataDir = ''): Loupe
    {
        if ($configuration === null) {
            $configuration = Configuration::create();
        }

        $configuration = $configuration
            ->withPrimaryKey('sku')
            ->withFilterableAttributes(['product_id', 'categories', 'price', 'isAvailable', 'ratings'])
            ->withSortableAttributes(['name', 'price'])
            ->withSearchableAttributes(['name', 'variant']);

        $loupe = $this->createLoupe($configuration, $dataDir);
        $this->indexFixture($loupe, 'products');

        return $loupe;
    }
}
