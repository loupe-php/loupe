<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;

/**
 * @BeforeClassMethods({"setUpClass"})
 * @BeforeMethods({"setUp"})
 * @Revs(3)
 * @Iterations(5)
 * @Warmup(2)
 * @OutputTimeUnit("milliseconds", precision=2)
 * @Groups({"query"})
 */
class FilterBench extends AbstractBench
{
    private Loupe $loupe;

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
    }

    public function benchFilteredSortedSearch(): void
    {
        $this->loupe->search(
            SearchParameters::create()
                ->withQuery('aircarft')
                ->withFilter("release_date < 1127433600 AND genres IN ('Drama', 'Western')")
                ->withSort(['release_date:desc'])
        );
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
    }
}
