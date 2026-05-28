<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;

/**
 * @BeforeClassMethods({"setUpClass"})
 * @BeforeMethods({"setUp"})
 * @Revs(10)
 * @Iterations(5)
 * @Warmup(2)
 * @OutputTimeUnit("milliseconds", precision=2)
 */
class SearchBench extends AbstractBench
{
    private Loupe $loupe;

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
    }

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
    }

    public function benchTypoQueryWithFacets(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('Amakin Dkywalker')
                ->withFacets(['genres'])
        );
    }

    public function benchPlainQuery(): void
    {
        $this->loupe->search(
            SearchParameters::create()->withQuery('star wars')
        );
    }
}
