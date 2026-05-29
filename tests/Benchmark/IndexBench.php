<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Loupe;
use PhpBench\Attributes\BeforeClassMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeClassMethods('setUpClass')]
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(1)]
#[Warmup(1)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['index'])]
class IndexBench extends AbstractBench
{
    private Loupe $loupe;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $movies;

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
            ? \array_slice($movies, 0, $params['size'])
            : $movies;
    }

    #[ParamProviders('provideCorpus')]
    public function benchAddDocuments(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    /**
     * Re-indexes the same documents on top of an already-populated index,
     * exercising the upsert path rather than cold insert.
     */
    #[BeforeMethods('setUpForUpdate')]
    #[ParamProviders('provideCorpus')]
    public function benchUpdateDocuments(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    public function provideCorpus(): \Generator
    {
        yield '1k' => [
            'size' => 1_000,
        ];
        yield '10k' => [
            'size' => 10_000,
        ];
        yield 'all' => [
            'size' => 0,
        ];
    }

    public static function setUpClass(): void
    {
        self::ensureMoviesJson();
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpForUpdate(array $params): void
    {
        $this->setUp($params);
        $this->loupe->addDocuments($this->movies);
    }
}
