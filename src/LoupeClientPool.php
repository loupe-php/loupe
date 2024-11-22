<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Manage a pool of Loupe clients, each representing a separate index.
 *
 * Usage:
 *
 * ```php
 * $pool = new LoupeClientPool('/path/to/indexes');
 * $configuration = Configuration::create()->withPrimaryKey('uid');
 * $client = $pool->get('movies', $configuration);
 * ```
 */

class LoupeClientPool
{
    /**
     * @var Loupe[]
     */
    protected array $clients = [];

    protected LoupeFactory $factory;

    protected Filesystem $filesystem;

    public function __construct(
        protected string $path
    ) {
        $this->factory = new LoupeFactory();
        $this->filesystem = new Filesystem();

        // Create the base directory if it doesn't exist
        if (!$this->filesystem->exists($this->path)) {
            $this->filesystem->mkdir($this->path);
        }
    }

    public function createIndex(string $index): void
    {
        $db = $this->indexPath($index);
        if (!$this->filesystem->exists($db)) {
            $this->filesystem->dumpFile($db, '');
        }
    }

    public function dropIndex(string $index): void
    {
        $dir = $this->indexDirectory($index);
        if ($this->filesystem->exists($dir)) {
            $this->filesystem->remove($dir);
        }
    }

    public function get(string $index, Configuration $configuration): Loupe
    {
        return $this->clients[$index] ??= $this->make($index, $configuration);
    }

    public function indexDirectory(string $index): string
    {
        return Path::makeAbsolute($index, $this->path);
    }

    public function indexExists(string $index): bool
    {
        return $this->filesystem->exists($this->indexPath($index));
    }

    public function indexPath(string $index): string
    {
        return Path::makeAbsolute("{$index}/loupe.db", $this->path);
    }

    public function make(string $index, Configuration $configuration): Loupe
    {
        $this->createIndex($index);

        return $this->factory->create($this->indexDirectory($index), $configuration);
    }
}
