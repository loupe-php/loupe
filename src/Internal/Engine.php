<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\Indexer;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Search\Searcher;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;

class Engine
{
    private const MIN_SQLITE_VERSION = '3.40.0';

    private IndexInfo $indexInfo;

    public function __construct(
        private Connection $connection,
        private Configuration $configuration,
        private Tokenizer $tokenizer,
        private Parser $filterParser
    ) {
        if (! $this->connection->getDriver() instanceof AbstractSQLiteDriver) {
            throw new \InvalidArgumentException('Only SQLite is supported.');
        }

        $version = $this->connection->executeQuery('select sqlite_version()')
            ->fetchOne();
        if (version_compare($version, self::MIN_SQLITE_VERSION, '<')) {
            throw new \InvalidArgumentException(sprintf(
                'You need at least version "%s" of SQLite.',
                self::MIN_SQLITE_VERSION
            ));
        }

        $this->registerSQLiteFunctions();

        $this->indexInfo = new IndexInfo($this);
    }

    public function addDocument(array $document): self
    {
        $indexer = new Indexer($this);
        $indexer->addDocument($document);

        return $this;
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getDocument(int|string $identifier): ?array
    {
        $document = $this->getConnection()
            ->fetchOne(
                sprintf('SELECT document FROM %s WHERE user_id = :id', IndexInfo::TABLE_NAME_DOCUMENTS),
                [
                    'id' => LoupeTypes::convertToString($identifier),
                ]
            );

        if ($document) {
            return Util::decodeJson($document);
        }

        return null;
    }

    public function getIndexInfo(): IndexInfo
    {
        return $this->indexInfo;
    }

    public function getTokenizer(): Tokenizer
    {
        return $this->tokenizer;
    }

    public function search(array $parameters): array
    {
        $searcher = new Searcher($this, $this->filterParser, $parameters);

        return $searcher->fetchResult();
    }

    private function registerSQLiteFunctions()
    {
        UserDefinedFunctions::register(
            [$this->connection->getNativeConnection(), 'sqliteCreateFunction'],
            [
                'levenshtein' => [
                    'callback' => [Levenshtein::class, 'levenshtein'],
                    'numArgs' => 2,
                ],
                'max_levenshtein' => [
                    'callback' => [Levenshtein::class, 'maxLevenshtein'],
                    'numArgs' => 3,
                ],
                'geo_distance' => [
                    'callback' => [Geo::class, 'geoDistance'],
                    'numArgs' => 4,
                ],
            ]
        );
    }
}
