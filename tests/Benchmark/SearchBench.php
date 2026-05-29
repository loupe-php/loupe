<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use PhpBench\Attributes\BeforeClassMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeClassMethods('setUpClass')]
#[BeforeMethods('setUp')]
#[Revs(3)]
#[Iterations(5)]
#[Warmup(2)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['query'])]
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

    #[Revs(1)]
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

    #[Revs(1)]
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
