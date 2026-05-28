<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;

/**
 * @BeforeClassMethods({"setUpClass"})
 * @BeforeMethods({"setUp"})
 * @Revs(1)
 * @Iterations(3)
 * @Warmup(1)
 * @OutputTimeUnit("milliseconds", precision=2)
 */
class IndexBench extends AbstractBench
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $movies;

    private Loupe $loupe;

    public static function setUpClass(): void
    {
        self::ensureMoviesJson();
    }

    /**
     * @param array{size: int} $params
     */
    public function setUp(array $params): void
    {
        $dir = self::indexScratchPath();
        self::clearDir($dir);

        $this->loupe = self::loupe($dir);

        $movies = self::loadMovies();
        $this->movies = $params['size'] > 0
            ? array_slice($movies, 0, $params['size'])
            : $movies;
    }

    /**
     * @ParamProviders({"provideCorpus"})
     */
    public function benchAddDocuments(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    public function provideCorpus(): \Generator
    {
        yield '1k' => ['size' => 1_000];
        yield '10k' => ['size' => 10_000];
        yield 'all' => ['size' => 0];
    }
}
