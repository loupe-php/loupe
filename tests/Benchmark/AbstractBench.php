<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;

abstract class AbstractBench
{
    protected const MOVIES_URL = 'https://www.meilisearch.com/movies.json';

    protected const SMALL_INDEX_SIZE = 8000;

    protected static function clearDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::CURRENT_AS_FILEINFO);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            }
        }
    }

    protected static function configuration(): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withFilterableAttributes(['release_date', 'genres'])
            ->withSortableAttributes(['release_date'])
            ->withLanguages(['en'])
            ->withVacuumProbability(0)
        ;
    }

    protected static function ensureMoviesJson(): void
    {
        $path = self::moviesJsonPath();

        if (is_file($path) && filesize($path) > 0) {
            return;
        }

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }

        self::progress(\sprintf('Downloading %s ...', self::MOVIES_URL));
        $start = microtime(true);

        $data = file_get_contents(self::MOVIES_URL);
        if (false === $data || '' === $data) {
            throw new \RuntimeException('Failed to download '.self::MOVIES_URL);
        }

        file_put_contents($path, $data);

        self::progress(\sprintf(
            'Downloaded %.1f MiB in %.1fs',
            \strlen($data) / 1024 / 1024,
            microtime(true) - $start,
        ));
    }

    /**
     * Idempotently builds the shared search/filter index. Reused across runs as
     * long as the SQLite database is intact and not out of date relative to the
     * current Loupe schema.
     */
    protected static function ensureSearchIndex(): void
    {
        self::ensureMoviesIndex(self::searchIndexPath(), null);
    }

    /**
     * Same as ensureSearchIndex() but only with the first SMALL_INDEX_SIZE movies. SQLite picks
     * different query plans depending on the index statistics, so this catches regressions that
     * only show on small indexes.
     */
    protected static function ensureSmallSearchIndex(): void
    {
        self::ensureMoviesIndex(self::smallSearchIndexPath(), self::SMALL_INDEX_SIZE);
    }

    protected static function indexScratchPath(): string
    {
        return self::projectRoot().'/var/bench/movies-index';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function loadMovies(): array
    {
        $json = file_get_contents(self::moviesJsonPath());
        if (false === $json) {
            throw new \RuntimeException('Failed to read '.self::moviesJsonPath());
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    protected static function loupe(string $dataDir): Loupe
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        return (new LoupeFactory())->create($dataDir, self::configuration());
    }

    protected static function moviesJsonPath(): string
    {
        return self::projectRoot().'/var/movies.json';
    }

    protected static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected static function searchIndexPath(): string
    {
        return self::projectRoot().'/var/bench/movies-search';
    }

    protected static function smallSearchIndexPath(): string
    {
        return self::projectRoot().'/var/bench/movies-search-'.self::SMALL_INDEX_SIZE;
    }

    private static function ensureMoviesIndex(string $dataDir, int|null $size): void
    {
        self::ensureMoviesJson();

        $loupe = self::loupe($dataDir);
        $count = $loupe->countDocuments();

        if (!$loupe->needsReindex() && $count > 0 && (null === $size || $count === $size)) {
            return;
        }

        // Rebuild from an empty database so schema benchmarks do not include freelist pages from the previous index.
        unset($loupe);
        self::clearDir($dataDir);

        $movies = self::loadMovies();
        if (null !== $size) {
            $movies = \array_slice($movies, 0, $size);
        }

        self::progress(\sprintf(
            'Building search index %s (%d documents, one-time) ...',
            basename($dataDir),
            \count($movies),
        ));
        $start = microtime(true);

        $loupe = self::loupe($dataDir);
        $loupe->addDocuments($movies);

        self::progress(\sprintf('Index built in %.1fs', microtime(true) - $start));
    }

    private static function progress(string $message): void
    {
        fwrite(STDERR, '[bench] '.$message.PHP_EOL);
    }
}
