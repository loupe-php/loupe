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
use Symfony\Component\Filesystem\Filesystem;

/**
 * Index benchmark.
 *
 * Warmup must be disabled here to ensure an empty index during runs.
 */
#[BeforeClassMethods('setUpClass')]
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(1)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['index'])]
class IndexBench extends AbstractBench
{
    private const BATCH_SIZES = [100, 1_000];

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

    /**
     * Measures a cold insert of new documents into an empty index.
     */
    #[ParamProviders('provideCorpus')]
    public function benchAddDocuments(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    /**
     * Re-indexes the same documents into a populated index.
     */
    #[BeforeMethods('setUpForUpdate')]
    #[ParamProviders('provideCorpus')]
    public function benchUpdateDocuments(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    #[BeforeMethods('setUpForBatchIntoPopulatedIndex')]
    #[ParamProviders('provideBatch')]
    #[Iterations(5)]
    public function benchAddBatchToPopulatedIndex(): void
    {
        $this->loupe->addDocuments($this->movies);
    }

    public function provideBatch(): \Generator
    {
        foreach (self::BATCH_SIZES as $size) {
            foreach (['unchanged' => 0, 'half new' => 50, 'new' => 100] as $label => $newPercentage) {
                yield \sprintf('%s, %s', number_format($size), $label) => [
                    'size' => $size,
                    'newPercentage' => $newPercentage,
                ];
            }
        }
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
     * @param array{size: int, newPercentage: int} $params
     */
    public function setUpForBatchIntoPopulatedIndex(array $params): void
    {
        self::ensurePopulatedIndex();

        // Start every iteration from an identical copy of the populated index
        $dir = self::indexScratchPath();
        (new Filesystem())->mirror(self::populatedIndexPath(), $dir, null, ['override' => true, 'delete' => true]);
        $this->loupe = self::loupe($dir);

        [$indexedMovies, $newMovies] = self::splitMoviesForPopulatedIndex();
        $newCount = intdiv($params['size'] * $params['newPercentage'], 100);

        $this->movies = [
            ...\array_slice($indexedMovies, 0, $params['size'] - $newCount),
            ...\array_slice($newMovies, 0, $newCount),
        ];
    }

    /**
     * @param array{size: int} $params
     */
    public function setUpForUpdate(array $params): void
    {
        $this->setUp($params);
        $this->loupe->addDocuments($this->movies);
    }

    private static function ensurePopulatedIndex(): void
    {
        $dir = self::populatedIndexPath();
        $loupe = self::loupe($dir);

        if (!$loupe->needsReindex() && $loupe->countDocuments() > 0) {
            return;
        }

        unset($loupe);
        self::clearDir($dir);

        $loupe = self::loupe($dir);
        $loupe->addDocuments(self::splitMoviesForPopulatedIndex()[0]);
    }

    private static function populatedIndexPath(): string
    {
        return self::projectRoot().'/var/bench/movies-index-populated';
    }

    /**
     * @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>} The indexed and the new movies
     */
    private static function splitMoviesForPopulatedIndex(): array
    {
        $movies = self::loadMovies();
        $newCount = max(self::BATCH_SIZES);

        return [\array_slice($movies, 0, -$newCount), \array_slice($movies, -$newCount)];
    }
}
