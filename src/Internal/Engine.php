<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\BrowseResult;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\IndexException;
use Loupe\Loupe\IndexResult;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\Indexer;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LanguageDetection\NitotmLanguageDetector;
use Loupe\Loupe\Internal\LanguageDetection\PreselectedLanguageDetector;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Loupe\Matcher\Formatter;
use Loupe\Matcher\Matcher;
use Psr\Log\LoggerInterface;
use Toflar\StateSetIndex\Alphabet\Utf8Alphabet;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\DataStore\NullDataStore;
use Toflar\StateSetIndex\StateSetIndex;

class Engine
{
    public const VERSION = '0.9.0'; // Increase this whenever a re-index of all documents is needed

    private Parser $filterParser;

    private Formatter $formatter;

    private Indexer $indexer;

    private IndexInfo $indexInfo;

    private string $sqliteVersion = '';

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
        $this->formatter = new Formatter(new Matcher($this->getTokenizer(), $this->configuration->getStopWords()));
        $this->filterParser = new Parser($this);
        $this->sqliteVersion = match (true) {
            \is_callable([$this->connection, 'getServerVersion']) => $this->connection->getServerVersion(), // @phpstan-ignore function.alreadyNarrowedType
            (($nativeConnection = $this->connection->getNativeConnection()) instanceof \SQLite3) => $nativeConnection->version()['versionString'],
            (($nativeConnection = $this->connection->getNativeConnection()) instanceof \PDO) => $nativeConnection->getAttribute(\PDO::ATTR_SERVER_VERSION),
        };
    }

    /**
     * @param array<array<string, mixed>> $documents
     */
    public function addDocuments(array $documents): IndexResult
    {
        return $this->indexer->addDocuments($documents);
    }

    public function browse(BrowseParameters $parameters): BrowseResult
    {
        if ($this->getIndexInfo()->needsSetup()) {
            return BrowseResult::createEmptyFromBrowseParameters($parameters);
        }

        return (new Searcher($this, $this->filterParser, $parameters))->fetchResult();
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

    public function deleteAllDocuments(): self
    {
        $this->indexer->deleteAllDocuments();

        return $this;
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
        if ($this->getIndexInfo()->needsSetup()) {
            return null;
        }

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

    public function getFormatter(): Formatter
    {
        return $this->formatter;
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

        $languages = $this->getConfiguration()->getLanguages();

        // Fast route if you configured only one language
        if (\count($languages) === 1) {
            $languageDetector = new PreselectedLanguageDetector($languages[0]);
        } else {
            $languageDetector = new NitotmLanguageDetector($languages);
        }

        return $this->tokenizer = new Tokenizer($this, $languageDetector);
    }

    public function needsReindex(): bool
    {
        // If nothing hasn't been indexed yet, by definition it is not a "re-index".
        if ($this->getIndexInfo()->needsSetup()) {
            return false;
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
        if ($this->getIndexInfo()->needsSetup()) {
            return SearchResult::createEmptyFromSearchParameters($parameters);
        }

        return (new Searcher($this, $this->filterParser, $parameters))->fetchResult();
    }

    /**
     * Returns the approx. size in bytes
     */
    public function size(): int
    {
        return (int) $this->connection
            ->executeQuery('SELECT (SELECT page_count FROM pragma_page_count) * (SELECT page_size FROM pragma_page_size)')
            ->fetchOne();
    }

    /**
     * Use native UPSERT if supported and fall back to a regular SELECT and INSERT INTO if not.
     * We do not use the Doctrine query builder in this method as the method  is heavily used and the query builder
     * will slow down performance considerably.
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

        // Use native UPSERT if possible
        if (version_compare($this->sqliteVersion, '3.35.0', '>=')) {
            $columns = [];
            $set = [];
            $values = [];
            $updateSet = [];
            $updateValues = [];
            foreach ($insertData as $columnName => $value) {
                $columns[] = $columnName;
                $set[] = '?';
                $values[] = $value;

                if (\in_array($columnName, $uniqueIndexColumns, true)) {
                    $updateSet[] = $columnName . ' = excluded.' . $columnName;
                } else {
                    $updateSet[] = $columnName . ' = ?';
                    $updateValues[] = $value;
                }
            }

            // Make sure the update values are added at the end for correct replacement
            $values = array_merge($values, $updateValues);

            $query = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')' .
                ' VALUES (' . implode(', ', $set) . ')' .
                ' ON CONFLICT (' . implode(', ', $uniqueIndexColumns) . ')';

            if (\count($updateSet) === 0) {
                $query .= ' DO NOTHING';
            } else {
                $query .= ' DO UPDATE SET ' . implode(', ', $updateSet);
            }

            if ($insertIdColumn !== '') {
                $query .= ' RETURNING ' . $insertIdColumn;
            }

            $insertValue = $this->getConnection()->executeQuery($query, $values, $this->extractDbalTypes($values))->fetchOne();

            if ($insertValue === false) {
                if ($insertIdColumn !== '') {
                    throw new IndexException('This should not happen!');
                }

                return null;
            }

            return (int) $insertValue;
        }

        // Fallback logic for older SQLite versions
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

            if ($insertIdColumn === '') {
                return null;
            }

            return (int) $this->getConnection()->lastInsertId();
        }

        $query = 'UPDATE ' . $table;

        $set = [];
        $parameters = [];
        foreach ($insertData as $columnName => $value) {
            $set[] = $columnName . '=?';
            $parameters[] = $value;
        }

        $query .= ' SET ' . implode(',', $set);

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
     * @param array<int<0, max>|string, mixed> $data
     * @return array<int<0, max>|string, \Doctrine\DBAL\ParameterType>
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
