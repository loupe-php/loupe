<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\ConnectionPool;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Logger\PrefixDecoratedLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoupeFactory implements LoupeFactoryInterface
{
    public const SQLITE_BUSY_TIMEOUT = 5000;

    public function create(string $dataDir, Configuration $configuration): Loupe
    {
        $dataDir = (string) realpath($dataDir);

        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0777, true)) {
                throw InvalidConfigurationException::becauseCouldNotCreateDataDir($dataDir);
            }
        }

        return $this->createFromConnectionPool(
            $this->createConnectionPool($configuration, $dataDir),
            $configuration,
            $dataDir
        );
    }

    public function createInMemory(Configuration $configuration): Loupe
    {
        return $this->createFromConnectionPool(
            $this->createConnectionPool($configuration),
            $configuration
        );
    }

    private function createConnection(string $connectionName, Configuration $configuration, ?string $databasePath = null): Connection
    {
        $dsnPart = $databasePath === null ? '/:memory:' : ('notused:inthis@case/' . $databasePath);
        $dsnParser = new DsnParser();

        return DriverManager::getConnection(
            $dsnParser->parse('pdo-sqlite://' . $dsnPart),
            $this->getDbalConfiguration($connectionName, $configuration)
        );
    }

    private function createConnectionPool(Configuration $configuration, ?string $dataDir = null): ConnectionPool
    {
        if ($dataDir === null) {
            $loupeConnection = $this->createConnection('loupe', $configuration);
            $ticketsConnection = $this->createConnection('tickets', $configuration);
        } else {
            $loupeConnection = $this->createConnection('loupe', $configuration, $dataDir . '/loupe.db');
            $ticketsConnection = $this->createConnection('loupe', $configuration, $dataDir . '/tickets.db');

        }

        return new ConnectionPool($loupeConnection, $ticketsConnection);
    }

    private function createFromConnectionPool(ConnectionPool $connectionPool, Configuration $configuration, ?string $dataDir = null): Loupe
    {
        // Always decorate the logger with our process name for easier tracking in concurrent environments
        $logger = $this->prefixLoggerWithProcessName($configuration, $configuration->getLogger() ?? new NullLogger());

        $engine = new Engine($connectionPool, $configuration, $logger, $dataDir);

        if ($dataDir !== null) {
            $this->optimizeSQLiteDatabase($connectionPool->loupeConnection);
            $this->optimizeSQLiteDatabase($connectionPool->ticketConnection);
        }

        $this->optimizeSQLiteConnection($connectionPool->loupeConnection);
        $this->optimizeSQLiteConnection($connectionPool->ticketConnection);

        return new Loupe($engine);
    }

    private function getDbalConfiguration(string $connectionName, Configuration $configuration): DbalConfiguration
    {
        $config = new DbalConfiguration();
        $middlewares = [];

        if ($configuration->getLogger() !== null) {
            // Prefix logger with connection and process names
            $logger = $configuration->getLogger();
            $logger = $this->prefixLoggerWithProcessName($configuration, $logger);
            $logger = new PrefixDecoratedLogger('db-' . $connectionName, $logger);

            // Prefix logs with connection name
            $middlewares[] = new Middleware($logger);
        }

        $config->setMiddlewares($middlewares);

        return $config;
    }

    private function optimizeSQLiteConnection(Connection $connection): void
    {
        $optimizations = [
            // Set cache size to 20MB to reduce disk i/o
            'PRAGMA cache_size = -20000',
            // Set mmap size to 32MB to avoid i/o for database reads
            'PRAGMA mmap_size = 33554432',
            // Store temporary tables in memory instead of on disk
            'PRAGMA temp_store = MEMORY',
            // Set timeout to 5 seconds to avoid locking issues
            'PRAGMA busy_timeout = ' . self::SQLITE_BUSY_TIMEOUT,
        ];

        foreach ($optimizations as $optimization) {
            try {
                $connection->executeStatement($optimization);
            } catch (\Throwable) {
                // Assume that the pragma is not supported
            }
        }
    }

    private function optimizeSQLiteDatabase(Connection $connection): void
    {
        $optimizations = [
            // Increase page size to 8KB to reduce disk i/o
            'PRAGMA page_size = 8192',
            // Enable write-ahead logging to allow concurrent reads and writes
            'PRAGMA journal_mode=WAL',
            // Incremental vacuum to keep the database size in check
            'PRAGMA auto_vacuum = incremental',
        ];

        foreach ($optimizations as $optimization) {
            try {
                $connection->executeStatement($optimization);
            } catch (\Throwable) {
                // Assume that the pragma is not supported
            }
        }
    }

    private function prefixLoggerWithProcessName(Configuration $configuration, LoggerInterface $logger): LoggerInterface
    {
        return new PrefixDecoratedLogger(
            $configuration->getProcessName(),
            $logger
        );
    }
}
