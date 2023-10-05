<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\IndexResult;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\Indexer;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\Search\Highlighter\Highlighter;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Nitotm\Eld\LanguageDetector;
use Psr\Log\LoggerInterface;
use Toflar\StateSetIndex\Alphabet\Utf8Alphabet;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\DataStore\NullDataStore;
use Toflar\StateSetIndex\StateSetIndex;

class Engine
{
    public const VERSION = '0.3.0'; // Increase this whenever a re-index of all documents is needed

    private Parser $filterParser;

    private Highlighter $highlighter;

    private Indexer $indexer;

    private IndexInfo $indexInfo;

    private StateSetIndex $stateSetIndex;

    private ?Tokenizer $tokenizer = null;

    public function __construct(
        private Connection $connection,
        private Configuration $configuration,
        private ?string $dataDir = null
    ) {
        $this->indexInfo = new IndexInfo($this);
        $this->stateSetIndex = new StateSetIndex(
            new Config(
                $this->configuration->getTypoTolerance()->getIndexLength(),
                $this->configuration->getTypoTolerance()->getAlphabetSize(),
            ),
            new Utf8Alphabet(),
            new StateSet($this),
            new NullDataStore()
        );
        $this->indexer = new Indexer($this);
        $this->highlighter = new Highlighter($this);
        $this->filterParser = new Parser();
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

    public function getDataDir(): ?string
    {
        return $this->dataDir;
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
        if ($this->tokenizer instanceof Tokenizer) {
            return $this->tokenizer;
        }

        $languageDetector = new LanguageDetector();
        $languageDetector->cleanText(true); // Clean stuff like URLs, domains etc. to improve language detection

        if ($this->getConfiguration()->getLanguages() !== []) {
            $languageDetector->langSubset($this->getConfiguration()->getLanguages()); // Save subset
        }

        return $this->tokenizer = new Tokenizer($languageDetector);
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

        $existing = $this->getConnection()->executeQuery($query, $parameters, $this->extractDbalTypes($parameters))->fetchAssociative();

        if ($existing === false) {
            $this->getConnection()->insert($table, $insertData, $this->extractDbalTypes($insertData));

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

        $this->getConnection()->executeStatement($query, $parameters, $this->extractDbalTypes($parameters));

        return $insertIdColumn !== '' ? (int) $existing[$insertIdColumn] : null;
    }

    /**
     * @param array<string|int, mixed> $data
     * @return  array<string|int, int>
     */
    private function extractDbalTypes(array $data): array
    {
        $types = [];

        foreach ($data as $k => $v) {
            $types[$k] = match (\gettype($v)) {
                'boolean' => ParameterType::BOOLEAN,
                'integer' => ParameterType::INTEGER,
                default => ParameterType::STRING
            };
        }

        return $types;
    }
}
