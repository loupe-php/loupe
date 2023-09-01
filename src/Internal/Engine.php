<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\IndexResult;
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
    public const VERSION = '0.3.0'; // Increase this whenever a re-index of all documents is needed

    private Indexer $indexer;

    private IndexInfo $indexInfo;

    private StateSetIndex $stateSetIndex;

    public function __construct(
        private Connection $connection,
        private Configuration $configuration,
        private Tokenizer $tokenizer,
        private Highlighter $highlighter,
        private Parser $filterParser
    ) {
        $this->indexInfo = new IndexInfo($this);
        $this->stateSetIndex = new StateSetIndex(
            new Config(
                $this->configuration->getTypoTolerance()->getIndexLength(),
                $this->configuration->getTypoTolerance()->getAlphabetSize(),
            ),
            new Alphabet($this),
            new StateSet($this)
        );
        $this->indexer = new Indexer($this);
    }

    /**
     * @param array<array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): IndexResult
    {
        return $this->indexer->addDocuments($documents);
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

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): self
    {
        $this->indexer->deleteDocuments($ids);

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

        // Do not use the query builder in this method as it is heavily used and the query builder will slow down
        // performance considerably here.
        $query = 'SELECT ' .
            implode(', ', array_filter(array_merge([$insertIdColumn], $uniqueIndexColumns))) .
            ' FROM ' .
            $table;

        $where = [];
        $parameters = [];
        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $where[] = $uniqueIndexColumn . '=?';
            $parameters[] = $insertData[$uniqueIndexColumn];
        }

        if ($where !== []) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $existing = $this->getConnection()->executeQuery($query, $parameters)->fetchAssociative();

        if ($existing === false) {
            $this->getConnection()->insert($table, $insertData);

            return (int) $this->getConnection()->lastInsertId();
        }

        $query = 'UPDATE ' . $table;

        $set = [];
        $parameters = [];
        foreach ($insertData as $columnName => $value) {
            $set[] = $columnName . '=?';
            $parameters[] = $value;
        }

        if ($set !== []) {
            $query .= ' SET ' . implode(',', $set);
        }

        $where = [];
        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $where[] = $uniqueIndexColumn . '=?';
            $parameters[] = $insertData[$uniqueIndexColumn];
        }

        if ($where !== []) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $this->getConnection()->executeStatement($query, $parameters);

        return $insertIdColumn !== '' ? (int) $existing[$insertIdColumn] : null;
    }
}
