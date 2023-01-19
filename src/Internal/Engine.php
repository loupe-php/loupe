<?php

namespace Terminal42\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Index\Indexer;
use Terminal42\Loupe\Internal\Search\Searcher;

class Engine
{

    private IndexInfo $indexInfo;

    public function __construct(private Connection $connection, private Configuration $configuration, private Parser $filterParser)
    {
        if (!$this->connection->getDriver() instanceof AbstractSQLiteDriver) {
            throw new \InvalidArgumentException('Only SQLite is supported.');
        }

        $this->registerSQLiteFunctions();

        $this->indexInfo = new IndexInfo($this);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getIndexInfo(): IndexInfo
    {
        return $this->indexInfo;
    }

    public function addDocument(array $document): self
    {
        $indexer = new Indexer($this);
        $indexer->addDocument($document);

        return $this;

    }

    private function registerSQLiteFunctions()
    {
        UserDefinedFunctions::register(
            [$this->connection->getNativeConnection(), 'sqliteCreateFunction'],
            [
                'levenshtein'  => ['callback' => [Levenshtein::class, 'levenshtein'], 'numArgs' => 2],
                'max_levenshtein' => ['callback' => [Levenshtein::class, 'maxLevenshtein'], 'numArgs' => 3],
            ]
        );
    }


    public function search(array $parameters): array
    {
        $searcher = new Searcher($this, $this->filterParser, $parameters);

        return $searcher->fetchResult();
    }
}