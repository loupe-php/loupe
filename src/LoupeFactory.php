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
use Loupe\Loupe\Internal\CosineSimilarity;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Geo;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Util;

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

        // Use Write-Ahead Logging if possible
        $connection->executeQuery('PRAGMA journal_mode=WAL;');

        $this->registerSQLiteFunctions($connection, $sqliteVersion);

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

        if ($configuration->getLogger() !== null) {
            $config->setMiddlewares([
                new Middleware($configuration->getLogger()),
            ]);
        }

        return $config;
    }

    private function registerSQLiteFunctions(Connection $connection, string $sqliteVersion): void
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
                'callback' => [CosineSimilarity::class, 'fromQuery'],
                'numArgs' => 4,
            ],
        ];

        // Introduction of LN()
        if (version_compare($sqliteVersion, '3.35.0', '<') || !$this->sqlLiteFunctionExists($connection, 'ln')) {
            $functions['ln'] = [
                'callback' => [Util::class, 'log'],
                'numArgs' => 1,
            ];
        }

        foreach ($functions as $functionName => $function) {
            /** @phpstan-ignore-next-line */
            $connection->getNativeConnection()->createFunction($functionName, $function['callback'], $function['numArgs']);
        }
    }

    private function sqlLiteFunctionExists(Connection $connection, string $function): bool
    {
        return (bool) $connection->executeQuery(
            'SELECT EXISTS(SELECT 1 FROM pragma_function_list WHERE name=?)',
            [$function]
        )->fetchOne();
    }
}
