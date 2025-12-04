<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\BrowseResult;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserterFactory;
use Loupe\Loupe\Internal\Index\Indexer;
use Loupe\Loupe\Internal\Index\IndexInfo;
use Loupe\Loupe\Internal\LanguageDetection\NitotmLanguageDetector;
use Loupe\Loupe\Internal\LanguageDetection\PreselectedLanguageDetector;
use Loupe\Loupe\Internal\Search\Searcher;
use Loupe\Loupe\Internal\Search\Sorting\Relevance;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult;
use Loupe\Matcher\Formatter;
use Loupe\Matcher\Matcher;
use Loupe\Matcher\StopWords\InMemoryStopWords;
use Loupe\Matcher\StopWords\StopWordsInterface;
use Pdo\Sqlite;
use Psr\Log\LoggerInterface;
use Toflar\StateSetIndex\Alphabet\Utf8Alphabet;
use Toflar\StateSetIndex\Config;
use Toflar\StateSetIndex\DataStore\NullDataStore;
use Toflar\StateSetIndex\StateSetIndex;

class Engine
{
    public const VERSION = '0.13.0'; // Increase this whenever a re-index of all documents is needed

    private BulkUpserterFactory $bulkUpserterFactory;

    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    private Parser $filterParser;

    private Formatter $formatter;

    private Indexer $indexer;

    private IndexInfo $indexInfo;

    private StateSetIndex $stateSetIndex;

    private StopwordsInterface $stopwords;

    private TicketHandler $ticketHandler;

    private ?Tokenizer $tokenizer = null;

    public function __construct(
        private ConnectionPool $connectionPool,
        private Configuration $configuration,
        private LoggerInterface $logger,
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
        $this->ticketHandler = new TicketHandler($this->connectionPool, $this->getLogger());
        $this->indexer = new Indexer($this, $this->ticketHandler);
        $this->stopwords = new InMemoryStopWords($this->configuration->getStopWords());
        $this->formatter = new Formatter(new Matcher($this->getTokenizer(), $this->stopwords));
        $this->filterParser = new Parser($this);
        $this->bulkUpserterFactory = new BulkUpserterFactory($this->connectionPool);

        $this->registerSQLiteFunctions($this->connectionPool->loupeConnection);
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @throws InvalidDocumentException
     */
    public function addDocuments(array $documents): self
    {
        if ($documents === []) {
            return $this;
        }

        $this->ticketHandler->claimTicket();

        try {
            $this->indexer->addDocuments($documents);
        } finally {
            $this->ticketHandler->release();
        }

        return $this;
    }

    public function browse(BrowseParameters $parameters): BrowseResult
    {
        if ($this->getIndexInfo()->needsSetup()) {
            return BrowseResult::createEmptyFromBrowseParameters($parameters);
        }

        try {
            return (new Searcher($this, $this->filterParser, $parameters))->fetchResult();
        } catch (Exception $exception) {
            // If we need a re-index (e.g. schema has changed via an update from an old to a newer Loupe version)
            // we return an empty result. Otherwise, we want to see the exception
            if ($this->needsReindex()) {
                return BrowseResult::createEmptyFromBrowseParameters($parameters);
            }

            throw $exception;
        }
    }

    public function countDocuments(): int
    {
        if ($this->getIndexInfo()->needsSetup()) {
            return 0;
        }

        return (int) $this->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(IndexInfo::TABLE_NAME_DOCUMENTS)
            ->fetchOne();
    }

    public function deleteAllDocuments(): self
    {
        $this->ticketHandler->claimTicket();

        try {
            $this->indexer->deleteAllDocuments();
        } finally {
            $this->ticketHandler->release();
        }

        return $this;
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): self
    {
        $this->ticketHandler->claimTicket();

        try {
            $this->indexer->deleteDocuments($ids);
        } finally {
            $this->ticketHandler->release();
        }

        return $this;
    }

    public function getBulkUpserterFactory(): BulkUpserterFactory
    {
        return $this->bulkUpserterFactory;
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getConnection(): Connection
    {
        return $this->connectionPool->loupeConnection;
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
                \sprintf('SELECT _document FROM %s WHERE _user_id = :id', IndexInfo::TABLE_NAME_DOCUMENTS),
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

    public function getIndexer(): Indexer
    {
        return $this->indexer;
    }

    public function getIndexInfo(): IndexInfo
    {
        return $this->indexInfo;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getStateSetIndex(): StateSetIndex
    {
        return $this->stateSetIndex;
    }

    public function getStopWords(): StopWordsInterface
    {
        return $this->stopwords;
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

        try {
            return (new Searcher($this, $this->filterParser, $parameters))->fetchResult();
        } catch (Exception $exception) {
            // If we need a re-index (e.g. schema has changed via an update from an old to a newer Loupe version)
            // we return an empty result. Otherwise, we want to see the exception
            if ($this->needsReindex()) {
                return SearchResult::createEmptyFromSearchParameters($parameters);
            }

            throw $exception;
        }
    }

    /**
     * Returns the approx. size in bytes
     */
    public function size(): int
    {
        return (int) $this->getConnection()
            ->executeQuery('SELECT (SELECT page_count FROM pragma_page_count) * (SELECT page_size FROM pragma_page_size)')
            ->fetchOne();
    }

    private function registerSQLiteFunctions(Connection $connection): void
    {
        $functions = [
            'loupe_max_levenshtein' => [
                'callback' => [Levenshtein::class, 'maxLevenshtein'],
                'numArgs' => 4,
            ],
            'loupe_levensthein' => [
                'callback' => [Levenshtein::class, 'damerauLevenshtein'],
                'numArgs' => 3,
            ],
            'loupe_geo_distance' => [
                'callback' => [Geo::class, 'geoDistance'],
                'numArgs' => 4,
            ],
            'loupe_relevance' => [
                'callback' => [Relevance::class, 'fromQuery'],
                'numArgs' => 3,
            ],
        ];

        foreach ($functions as $functionName => $function) {
            $nativeConnection = $connection->getNativeConnection();

            // PHP 8.4+
            if (class_exists(Sqlite::class) && $nativeConnection instanceof Sqlite) {
                $nativeConnection->createFunction(
                    $functionName,
                    $this->wrapSQLiteMethodForCache($functionName, $function['callback']),
                    $function['numArgs']
                );
            } elseif ($nativeConnection instanceof \PDO) {
                $nativeConnection->sqliteCreateFunction(
                    $functionName,
                    $this->wrapSQLiteMethodForCache($functionName, $function['callback']),
                    $function['numArgs']
                );
            } else {
                throw new \LogicException('This here should not happen.');
            }
        }
    }

    private function wrapSQLiteMethodForCache(string $prefix, callable $callback): \Closure
    {
        return function () use ($prefix, $callback) {
            $args = \func_get_args();
            $cacheKey = $prefix . ':' . implode('--', $args);
            $cachedValue = $this->cache[$cacheKey] ?? null;

            if ($cachedValue !== null) {
                return $cachedValue;
            }

            return $this->cache[$cacheKey] = \call_user_func_array($callback, $args);
        };
    }
}
