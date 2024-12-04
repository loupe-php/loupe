<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\Doctrine\CachePreparedStatementsMiddleware;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Geo;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Search\Sorting\Relevance;

final class LoupeFactory
{
    private const MIN_SQLITE_VERSION = '3.16.0'; // Introduction of Pragma functions

    public function create(string $dataDir, Configuration $configuration): Loupe
    {
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0777, true)) {
                throw InvalidConfigurationException::becauseCouldNotCreateDataDir($dataDir);
            }
        }

        return $this->createFromConnection($this->createConnection($configuration, $dataDir), $configuration, $dataDir);
    }

    public function createInMemory(Configuration $configuration): Loupe
    {
        return $this->createFromConnection($this->createConnection($configuration), $configuration);
    }

    public function isSupported(): bool
    {
        try {
            $this->createConnection(Configuration::create());
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function createConnection(Configuration $configuration, ?string $folder = null): Connection
    {
        $connection = null;
        $dsnPart = $folder === null ? '/:memory:' : ('notused:inthis@case/' . realpath($folder) . '/loupe.db');
        $dsnParser = new DsnParser();

        // Try sqlite3 first, it seems way faster than the pdo-sqlite driver
        try {
            $connection = DriverManager::getConnection(
                $dsnParser->parse('sqlite3://' . $dsnPart),
                $this->getDbalConfiguration($configuration)
            );
        } catch (Exception) {
            try {
                $connection = DriverManager::getConnection(
                    $dsnParser->parse('pdo_sqlite://' . $dsnPart),
                    $this->getDbalConfiguration($configuration)
                );
            } catch (Exception) {
                // Noop
            }
        }

        if ($connection === null) {
            throw new InvalidConfigurationException('You need either the sqlite3 (recommended) or pdo_sqlite PHP extension.');
        }

        $sqliteVersion = match (true) {
            \is_callable([$connection, 'getServerVersion']) => $connection->getServerVersion(),
            (($nativeConnection = $connection->getNativeConnection()) instanceof \SQLite3) => $nativeConnection->version()['versionString'],
            (($nativeConnection = $connection->getNativeConnection()) instanceof \PDO) => $nativeConnection->getAttribute(\PDO::ATTR_SERVER_VERSION),
        };

        if (version_compare($sqliteVersion, self::MIN_SQLITE_VERSION, '<')) {
            throw new \InvalidArgumentException(sprintf(
                'You need at least version "%s" of SQLite.',
                self::MIN_SQLITE_VERSION
            ));
        }

        $this->optimizeSQLiteConnection($connection);

        $this->registerSQLiteFunctions($connection);

        return $connection;
    }

    private function createFromConnection(Connection $connection, Configuration $configuration, ?string $dataDir = null): Loupe
    {
        return new Loupe(
            new Engine(
                $connection,
                $configuration,
                $dataDir
            )
        );
    }

    private function getDbalConfiguration(Configuration $configuration): DbalConfiguration
    {
        $config = new DbalConfiguration();
        $middlewares = [new CachePreparedStatementsMiddleware()];

        if ($configuration->getLogger() !== null) {
            $middlewares[] = new Middleware($configuration->getLogger());
        }

        $config->setMiddlewares($middlewares);

        return $config;
    }

    private function optimizeSQLiteConnection(Connection $connection): void
    {
        $optimizations = [
            // Incremental vacuum to keep the database size in check (vacuum to apply the changes)
            'PRAGMA auto_vacuum = incremental; VACUUM;',
            // Temporary disable WAL to allow setting page size
            'PRAGMA journal_mode=DELETE;',
            // Increase page size to 8KB to reduce disk i/o  (vacuum to apply the changes)
            'PRAGMA page_size = 8192; VACUUM;',
            // Now enable write-ahead logging to allow concurrent reads and writes
            'PRAGMA journal_mode=WAL;',
            // Set cache size to 20MB to reduce disk i/o
            'PRAGMA cache_size = -20000;',
            // Incremental vacuum to keep the database size in check
            'PRAGMA incremental_vacuum;',
            // Set mmap size to 32MB to avoid i/o for database reads
            'PRAGMA mmap_size = 33554432;',
            // Store temporary tables in memory instead of on disk
            'PRAGMA temp_store = MEMORY;',
            // Set timeout to 2 seconds to avoid locking issues
            'PRAGMA busy_timeout = 2000',
        ];

        foreach ($optimizations as $optimization) {
            try {
                $connection->executeQuery($optimization);
            } catch (\Throwable $th) {
                // Assume that the pragma is not supported
            }
        }
    }

    private function registerSQLiteFunctions(Connection $connection): void
    {
        $functions = [
            'loupe_max_levenshtein' => [
                'callback' => [Levenshtein::class, 'maxLevenshtein'],
                'numArgs' => 4,
            ],
            'loupe_geo_distance' => [
                'callback' => [Geo::class, 'geoDistance'],
                'numArgs' => 4,
            ],
            'loupe_relevance' => [
                'callback' => [Relevance::class, 'fromQuery'],
                'numArgs' => 3,
            ],
        ];

        foreach ($functions as $functionName => $function) {
            /** @phpstan-ignore-next-line */
            $connection->getNativeConnection()->createFunction($functionName, $function['callback'], $function['numArgs']);
        }
    }
}
