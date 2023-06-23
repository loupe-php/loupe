<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Index\Indexer;
use Terminal42\Loupe\Internal\Index\IndexInfo;
use Terminal42\Loupe\Internal\Search\Highlighter\Highlighter;
use Terminal42\Loupe\Internal\Search\Searcher;
use Terminal42\Loupe\Internal\StateSet\Alphabet;
use Terminal42\Loupe\Internal\StateSet\StateSet;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;
use Terminal42\Loupe\SearchParameters;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\StateSetIndex;

class Engine
{
    private const MIN_SQLITE_VERSION = '3.35.0'; // Introduction of LN()

    private IndexInfo $indexInfo;

    private StateSetIndex $stateSetIndex;

    public function __construct(
        private Connection $connection,
        private Configuration $configuration,
        private Tokenizer $tokenizer,
        private Highlighter $highlighter,
        private Parser $filterParser
    ) {
        if (! $this->connection->getDriver() instanceof AbstractSQLiteDriver) {
            throw new \InvalidArgumentException('Only SQLite is supported.');
        }

        $version = $this->connection->executeQuery('SELECT sqlite_version()')
            ->fetchOne();
        if (version_compare($version, self::MIN_SQLITE_VERSION, '<')) {
            throw new \InvalidArgumentException(sprintf(
                'You need at least version "%s" of SQLite.',
                self::MIN_SQLITE_VERSION
            ));
        }

        // Use Write-Ahead Logging if possible
        $this->connection->executeQuery('PRAGMA journal_mode=WAL;');

        $this->registerSQLiteFunctions();

        $this->indexInfo = new IndexInfo($this);
        $this->stateSetIndex = new StateSetIndex(
            new Config(16, 23), // TODO: should come from Configuration
            new Alphabet($this),
            new StateSet($this)
        );
    }

    public function addDocuments(array $documents): self
    {
        $indexer = new Indexer($this);
        $indexer->addDocuments($documents);

        return $this;
    }

    public function countDocuments(): int
    {
        if ($this->getIndexInfo()->needsSetup()) {
            return 0;
        }

        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS)
            ->fetchOne();
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

    public function getHighlighter(): Highlighter
    {
        return $this->highlighter;
    }

    public function getIndexInfo(): IndexInfo
    {
        return $this->indexInfo;
    }

    public function getStateSetIndex(): StateSetIndex
    {
        return $this->stateSetIndex;
    }

    public function getTokenizer(): Tokenizer
    {
        return $this->tokenizer;
    }

    public function search(SearchParameters $parameters): array
    {
        $searcher = new Searcher($this, $this->filterParser, $parameters);

        return $searcher->fetchResult();
    }

    /**
     * Unfortunately, we cannot use proper UPSERTs here (ON DUPLICATE() UPDATE) as somehow RETURNING does not work
     * properly with Doctrine. Maybe we can improve that one day.
     *
     * @return int The ID of the $insertIdColumn (either new when INSERT or existing when UPDATE)
     */
    public function upsert(
        string $table,
        array $insertData,
        array $uniqueIndexColumns,
        string $insertIdColumn = ''
    ): ?int {
        if (count($insertData) === 0) {
            throw new \InvalidArgumentException('Need to provide data to insert.');
        }

        $qb = $this->getConnection()->createQueryBuilder()
            ->select(array_filter(array_merge([$insertIdColumn], $uniqueIndexColumns)))
            ->from($table);

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $existing = $qb->executeQuery()
            ->fetchAssociative();

        if ($existing === false) {
            $this->getConnection()->insert($table, $insertData);

            return (int) $this->getConnection()->lastInsertId();
        }

        $qb = $this->getConnection()->createQueryBuilder()
            ->update($table);

        foreach ($insertData as $columnName => $value) {
            $qb->set($columnName, $qb->createPositionalParameter($value));
        }

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $qb->executeQuery();

        return $insertIdColumn !== '' ? (int) $existing[$insertIdColumn] : null;
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
                'loupe_relevance' => [
                    'callback' => [CosineSimilarity::class, 'fromQuery'],
                    'numArgs' => 3,
                ],
            ]
        );
    }
}
