<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;

abstract class AbstractBench
{
    protected const MOVIES_URL = 'https://www.meilisearch.com/movies.json';

    protected static function moviesJsonPath(): string
    {
        return self::projectRoot() . '/var/movies.json';
    }

    protected static function searchIndexPath(): string
    {
        return self::projectRoot() . '/var/bench/movies-search';
    }

    protected static function indexScratchPath(): string
    {
        return self::projectRoot() . '/var/bench/movies-index';
    }

    protected static function projectRoot(): string
    {
        return \dirname(__DIR__, 2);
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

        self::progress(sprintf('Downloading %s ...', self::MOVIES_URL));
        $start = microtime(true);

        $data = file_get_contents(self::MOVIES_URL);
        if ($data === false || $data === '') {
            throw new \RuntimeException('Failed to download ' . self::MOVIES_URL);
        }

        file_put_contents($path, $data);

        self::progress(sprintf(
            'Downloaded %.1f MiB in %.1fs',
            strlen($data) / 1024 / 1024,
            microtime(true) - $start
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function loadMovies(): array
    {
        return json_decode(
            file_get_contents(self::moviesJsonPath()),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    protected static function configuration(): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['title', 'overview'])
            ->withFilterableAttributes(['release_date', 'genres'])
            ->withSortableAttributes(['release_date'])
            ->withLanguages(['en']);
    }

    protected static function loupe(string $dataDir): Loupe
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        return (new LoupeFactory())->create($dataDir, self::configuration());
    }

    /**
     * Idempotently builds the shared search/filter index. Reused across runs as
     * long as the SQLite database is intact and not out of date relative to the
     * current Loupe schema.
     */
    protected static function ensureSearchIndex(): void
    {
        self::ensureMoviesJson();

        $dataDir = self::searchIndexPath();
        $loupe = self::loupe($dataDir);

        if (!$loupe->needsReindex() && $loupe->countDocuments() > 0) {
            return;
        }

        $movies = self::loadMovies();
        self::progress(sprintf(
            'Building shared search index (%d documents, one-time) ...',
            \count($movies)
        ));
        $start = microtime(true);

        $loupe->deleteAllDocuments();
        $loupe->addDocuments($movies);

        self::progress(sprintf('Index built in %.1fs', microtime(true) - $start));
    }

    private static function progress(string $message): void
    {
        fwrite(STDERR, '[bench] ' . $message . \PHP_EOL);
    }

    protected static function clearDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \FilesystemIterator($dir) as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            }
        }
    }
}
