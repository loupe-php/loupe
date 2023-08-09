<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\LoupeExceptionInterface;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\Indexer;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Highlighter\Highlighter;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\StateSet\Alphabet;
use Loupe\Loupe\Internal\StateSet\StateSet;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Psr\Log\LoggerInterface;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\StateSetIndex;

class Engine
{
    public const VERSION = '1.0.0'; // Increase this whenever a re-index of all documents is needed

    private const MIN_SQLITE_VERSION = '3.16.0'; // Introduction of Pragma functions

    private IndexInfo $indexInfo;

    private StateSetIndex $stateSetIndex;

    public function __construct(
        private Connection $connection,
        private Configuration $configuration,
        private Tokenizer $tokenizer,
        private Highlighter $highlighter,
        private Parser $filterParser
    ) {
        if (! $this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
            throw new \InvalidArgumentException('Only SQLite is supported.');
        }

        $sqliteVersion = $this->connection->executeQuery('SELECT sqlite_version()')
            ->fetchOne();

        if (version_compare($sqliteVersion, self::MIN_SQLITE_VERSION, '<')) {
            throw new \InvalidArgumentException(sprintf(
                'You need at least version "%s" of SQLite.',
                self::MIN_SQLITE_VERSION
            ));
        }

        // Use Write-Ahead Logging if possible
        $this->connection->executeQuery('PRAGMA journal_mode=WAL;');

        $this->registerSQLiteFunctions($sqliteVersion);

        $this->indexInfo = new IndexInfo($this);
        $this->stateSetIndex = new StateSetIndex(
            new Config(
                $this->configuration->getTypoTolerance()->getIndexLength(),
                $this->configuration->getTypoTolerance()->getAlphabetSize(),
            ),
            new Alphabet($this),
            new StateSet($this)
        );
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @throws LoupeExceptionInterface
     */
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

    /**
     * @return array<string, mixed>|null
     */
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

    public function getLogger(): ?LoggerInterface
    {
        return $this->getConfiguration()->getLogger();
    }

    public function getStateSetIndex(): StateSetIndex
    {
        return $this->stateSetIndex;
    }

    public function getTokenizer(): Tokenizer
    {
        return $this->tokenizer;
    }

    public function needsReindex(): bool
    {
        if ($this->getIndexInfo()->needsSetup()) {
            return true;
        }

        if ($this->getIndexInfo()->getEngineVersion() !== self::VERSION) {
            return true;
        }

        if ($this->getIndexInfo()->getConfigHash() !== $this->getConfiguration()->getIndexHash()) {
            return true;
        }

        return false;
    }

    public function search(SearchParameters $parameters): SearchResult
    {
        return (new Searcher($this, $this->filterParser, $parameters))->fetchResult();
    }

    /**
     * Unfortunately, we cannot use proper UPSERTs here (ON DUPLICATE() UPDATE) as somehow RETURNING does not work
     * properly with Doctrine. Maybe we can improve that one day.
     *
     * @param array<string, mixed> $insertData
     * @param array<string> $uniqueIndexColumns
     * @return int The ID of the $insertIdColumn (either new when INSERT or existing when UPDATE)
     */
    public function upsert(
        string $table,
        array $insertData,
        array $uniqueIndexColumns,
        string $insertIdColumn = ''
    ): ?int {
        if (\count($insertData) === 0) {
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

    private function registerSQLiteFunctions(string $sqliteVersion): void
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
        if (version_compare($sqliteVersion, '3.35.0', '<')) {
            $functions['ln'] = [
                'callback' => [Util::class, 'log'],
                'numArgs' => 1,
            ];
        }

        /** @phpstan-ignore-next-line */
        UserDefinedFunctions::register([$this->connection->getNativeConnection(), 'sqliteCreateFunction'], $functions);
    }
}
