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
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * @phpstan-type FacetQueryParams array{query: string, facets: list<string>, filter?: string, distinct?: string, rankingScoreThreshold?: float}
 */
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

    public function setUpSmallIndex(): void
    {
        $this->loupe = self::loupe(self::smallSearchIndexPath());
    }

    public function benchExactQueryWithFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('Anakin Skywalker')
                ->withFacets(['genres']),
        );
    }

    /**
     * @param array{query: string, filter: string} $params
     */
    #[ParamProviders('provideFilteredQueries')]
    public function benchFilteredRankedQuery(array $params): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery($params['query'])
                ->withFilter($params['filter']),
        );
    }

    /**
     * @param array{query: string, filter: string} $params
     */
    #[ParamProviders('provideFilteredQueries')]
    public function benchFilteredFormattedQuery(array $params): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery($params['query'])
                ->withFilter($params['filter'])
                ->withAttributesToHighlight(['title', 'overview'])
                ->withAttributesToCrop(['overview'], 80)
                ->withShowMatchesPosition(true),
        );
    }

    /**
     * @param array{attributesToHighlight?: list<string>, attributesToCrop?: list<string>} $params
     */
    #[ParamProviders('providePartialFormatting')]
    #[Revs(1)]
    public function benchPartiallyFormattedQueries(array $params): void
    {
        $queries = [
            'love',
            'war',
            'family',
            'dark',
            'the world',
            'young woman',
            'police detective',
            'school friends',
        ];

        foreach ($queries as $q) {
            $this->loupe->search(SearchParameters::fromArray([...$params, 'query' => $q]));
        }
    }

    /**
     * @return \Generator<string, array{attributesToHighlight?: list<string>, attributesToCrop?: list<string>}>
     */
    public function providePartialFormatting(): \Generator
    {
        yield 'highlight title' => ['attributesToHighlight' => ['title']];
        yield 'crop title' => ['attributesToCrop' => ['title']];
        yield 'highlight title and overview' => ['attributesToHighlight' => ['title', 'overview']];
    }

    /**
     * @return \Generator<string, array{query: string, filter: string}>
     */
    public function provideFilteredQueries(): \Generator
    {
        yield 'common term, selective filter' => [
            'query' => 'family',
            'filter' => 'release_date < 0',
        ];
        yield 'common term, broad filter' => [
            'query' => 'family',
            'filter' => 'release_date > 0',
        ];
        yield 'narrow typo query' => [
            'query' => 'aircarft',
            'filter' => "release_date < 1127433600 AND genres IN ('Drama', 'Western')",
        ];
    }

    /**
     * @param FacetQueryParams $params
     */
    #[ParamProviders('provideFacetQueries')]
    public function benchFacetQueryWithOneHit(array $params): void
    {
        $this->loupe->search(SearchParameters::fromArray($params)->withLimit(1));
    }

    /**
     * @param FacetQueryParams $params
     */
    #[ParamProviders('provideFacetQueries')]
    public function benchFacetOnlyQuery(array $params): void
    {
        $this->loupe->search(SearchParameters::fromArray($params)->withLimit(0));
    }

    /**
     * @return \Generator<string, FacetQueryParams>
     */
    public function provideFacetQueries(): \Generator
    {
        $params = ['query' => 'family', 'facets' => ['genres', 'release_date']];

        yield 'common term' => $params;
        yield 'selective filter' => [...$params, 'filter' => 'release_date < 0'];
        yield 'distinct' => [...$params, 'distinct' => 'release_date'];
        yield 'ranking threshold' => [...$params, 'rankingScoreThreshold' => 0.99];
    }

    public function benchQueryWithDistinctAndFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('star wars')
                ->withFacets(['genres'])
                ->withDistinct('release_date'),
        );
    }

    public function benchQueryWithDistinct(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('star wars')
                ->withDistinct('release_date'),
        );
    }

    public function benchQueryWithFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('star wars')
                ->withFacets(['genres']),
        );
    }

    #[Revs(1)]
    public function benchMultiQueryOnAttributes(): void
    {
        $attributes = [
            ['title'],
            ['overview'],
            ['title', 'overview'],
        ];

        foreach ($attributes as $attr) {
            $this->loupe->search(
                SearchParameters::create()
                    ->withQuery('Anakin Skywalker')
                    ->withAttributesToSearchOn($attr),
            );
        }
    }

    #[Revs(1)]
    public function benchSingleQueryOnAttributes(): void
    {
        $attributes = [
            ['title'],
            ['overview'],
            ['title', 'overview'],
        ];

        foreach ($attributes as $attr) {
            $this->loupe->search(
                SearchParameters::create()
                    ->withQuery('knight')
                    ->withAttributesToSearchOn($attr),
            );
        }
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

    #[Revs(1)]
    public function benchNegatedTermQueries(): void
    {
        $queries = [
            'family -therapist',
            'duck -duffy',
            'festival -cannes',
        ];

        foreach ($queries as $q) {
            $this->loupe->search(SearchParameters::create()->withQuery($q));
        }
    }

    #[Revs(1)]
    public function benchPhraseGroupQueries(): void
    {
        $queries = [
            '"by her family"',
            '"every day"',
            '"involved in"',
            '"lead role"',
            '"down on his luck"',
        ];

        foreach ($queries as $q) {
            $this->loupe->search(SearchParameters::create()->withQuery($q));
        }
    }

    public function benchPlainQuery(): void
    {
        $this->loupe->search(
            SearchParameters::create()->withQuery('star wars'),
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

    /**
     * Control run on a smaller index. SQLite picks query plans based on the index statistics, so plans that are
     * fine on the shared index can degrade on smaller ones.
     */
    #[Revs(1)]
    #[BeforeMethods('setUpSmallIndex')]
    public function benchSmallIndexQueries(): void
    {
        $queries = [
            'Anakin',
            'Anakin Skywalker',
            'Skywalkr',
            'lov',
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
                ->withFacets(['genres']),
        );
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
        self::ensureSmallSearchIndex();
    }
}
