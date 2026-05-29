<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use PhpBench\Attributes as Bench;

#[Bench\BeforeClassMethods('setUpClass')]
#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(3)]
#[Bench\Iterations(5)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('milliseconds', precision: 2)]
#[Bench\Groups(['query'])]
class SearchBench extends AbstractBench
{
    private Loupe $loupe;

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
    }

    public function benchExactQueryWithFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('Anakin Skywalker')
                ->withFacets(['genres'])
        );
    }

    #[Bench\Revs(1)]
    public function benchMultiWordQueries(): void
    {
        $queries = [
            'dark knight',
            'lord of the rings',
            'harry potter',
            'indiana jones',
            'jurassic park',
            'iron man',
            'back to the future',
            'the godfather',
        ];

        foreach ($queries as $q) {
            $this->loupe->search(SearchParameters::create()->withQuery($q));
        }
    }

    public function benchPlainQuery(): void
    {
        $this->loupe->search(
            SearchParameters::create()->withQuery('star wars')
        );
    }

    #[Bench\Revs(1)]
    public function benchSingleWordQueries(): void
    {
        $queries = [
            'batman',
            'wolf',
            'vampire',
            'ghost',
            'paris',
            'fire',
            'christmas',
            'dark',
        ];

        foreach ($queries as $q) {
            $this->loupe->search(SearchParameters::create()->withQuery($q));
        }

    }

    public function benchTypoQueryWithFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('Amakin Dkywalker')
                ->withFacets(['genres'])
        );
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
    }
}
