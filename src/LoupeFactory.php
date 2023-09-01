<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Logging\Middleware;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\CosineSimilarity;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Geo;
use Loupe\Loupe\Internal\Levenshtein;
use Loupe\Loupe\Internal\Search\Highlighter\Highlighter;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Loupe\Internal\Util;
use Nitotm\Eld\LanguageDetector;

final class LoupeFactory
{
    private const MIN_SQLITE_VERSION = '3.16.0'; // Introduction of Pragma functions

    public function create(string $dbPath, Configuration $configuration): Loupe
    {
        if (!file_exists($dbPath)) {
            throw InvalidConfigurationException::becauseInvalidDbPath($dbPath);
        }

        return $this->createFromConnection($this->createConnection($configuration, $dbPath), $configuration);
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

    private function createConnection(Configuration $configuration, ?string $dbPath = null): Connection
    {
        $connection = null;
        $dsnPart = $dbPath === null ? ':memory:' : ('notused:inthis@case/' . realpath($dbPath));

        // Try sqlite3 first, it seems way faster than the pdo-sqlite driver
        try {
            $connection = DriverManager::getConnection([
                'url' => 'sqlite3://' . $dsnPart,
            ], $this->getDbalConfiguration($configuration));
        } catch (Exception $e) {
            try {
                $connection = DriverManager::getConnection([
                    'url' => 'pdo-sqlite://' . $dsnPart,
                ], $this->getDbalConfiguration($configuration));
            } catch (Exception $e) {
                // Noop
            }
        }

        if ($connection === null) {
            throw new InvalidConfigurationException('You need either the sqlite3 (recommended) or pdo_sqlite PHP extension.');
        }

        /** @var \PDO $nativeConnection */
        $nativeConnection = $connection->getNativeConnection(); // Must be \PDO because we know what we instantiate here

        $sqliteVersion = $nativeConnection->getAttribute(\PDO::ATTR_SERVER_VERSION);

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

    private function createFromConnection(Connection $connection, Configuration $configuration): Loupe
    {
        $tokenizer = $this->createTokenizer($configuration);

        return new Loupe(
            new Engine(
                $connection,
                $configuration,
                $tokenizer,
                new Highlighter($configuration, $tokenizer),
                new Parser()
            )
        );
    }

    private function createTokenizer(Configuration $configuration): Tokenizer
    {
        $languageDetector = new LanguageDetector();
        $languageDetector->cleanText(true); // Clean stuff like URLs, domains etc. to improve language detection

        if ($configuration->getLanguages() !== []) {
            $languageDetector->langSubset($configuration->getLanguages()); // Save subset
        }

        return new Tokenizer($languageDetector);
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
            'max_levenshtein' => [
                'callback' => [Levenshtein::class, 'maxLevenshtein'],
                'numArgs' => 4,
            ],
            'geo_distance' => [
                'callback' => [Geo::class, 'geoDistance'],
                'numArgs' => 4,
            ],
            'loupe_relevance' => [
                'callback' => [CosineSimilarity::class, 'fromQuery'],
                'numArgs' => 3,
            ],
        ];

        // Introduction of LN()
        if (version_compare($sqliteVersion, '3.35.0', '<') || !$this->sqlLiteFunctionExists($connection, 'ln')) {
            $functions['ln'] = [
                'callback' => [Util::class, 'log'],
                'numArgs' => 1,
            ];
        }

        /** @phpstan-ignore-next-line */
        UserDefinedFunctions::register([$connection->getNativeConnection(), 'sqliteCreateFunction'], $functions);
    }

    private function sqlLiteFunctionExists(Connection $connection, string $function): bool
    {
        return (bool) $connection->executeQuery(
            'SELECT EXISTS(SELECT 1 FROM pragma_function_list WHERE name=?)',
            [$function]
        )->fetchOne();
    }
}
