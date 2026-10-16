<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Doctrine\DBAL\ArrayParameterType;
use Loupe\Loupe\Exception\IndexException;
use Loupe\Loupe\Indexing\DocumentSourceInterface;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpsertConfig;
use Loupe\Loupe\Internal\Index\BulkUpserter\BulkUpserter;
use Loupe\Loupe\Internal\Index\BulkUpserter\ConflictMode;
use Loupe\Loupe\Internal\Index\PreparedDocument\MultiAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\SingleAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\Term;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\TicketHandler;
use Loupe\Loupe\Internal\Util;

class Indexer
{
    /**
     * Documents can be of arbitrary configurations. You might have a lot of documents with very little content or only
     * a little amount of documents but each one of them with huge amounts of content. Hence, splitting by the number
     * of documents does not make any sense. We need to batch indexing by the number of terms because those generate the
     * heaviest queries. Technically, we might also do this for the number of other attributes that people want to filter
     * for, but it is rather unrealistic to have documents with thousands of values people want to filter (not search!) for.
     * The higher this number is, the faster the indexing process is going to be but the more memory is required. For now,
     * tests have shown a good result with 16_000 terms in our benchmark setup, balancing indexing throughput and
     * memory usage, but we might want to make this configurable one day.
     * However, it's also a bit hard to document and understand so for now, let's keep this internal.
     */
    private const MAX_TERMS_PER_BATCH = 16000;

    private const SOURCE_HASH_BATCH_SIZE = 1000;

    /**
     * Well below SQLITE_MAX_VARIABLE_NUMBER (32766 since SQLite 3.32), which builds may lower at compile time.
     */
    private const MAX_IDS_PER_QUERY = 5000;

    /**
     * @var array<int, callable>
     */
    private array $changes = [];

    /**
     * Map of id => content hash loaded once per addDocuments() to skip expensive tokenization for unchanged documents.
     *
     * @var array<string, string>
     */
    private array $existingHashes = [];

    /**
     * @var array<int, true>
     */
    private array $orphanCandidateTermIds = [];

    public function __construct(
        private readonly Engine $engine,
        private readonly TicketHandler $ticketHandler,
    ) {
    }

    /**
     * @param non-empty-array<array<string, mixed>>|DocumentSourceInterface $documents
     */
    public function addDocuments(DocumentSourceInterface|array $documents): void
    {
        if ($documents instanceof DocumentSourceInterface) {
            $this->addDocumentsFromSource($documents);

            return;
        }

        $this->addDocumentsFromArray($documents);
    }

    public function deleteAllDocuments(): void
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return;
        }

        $this->recordChange(
            function (): void {
                $connection = $this->engine->getConnection();
                $connection->executeStatement(\sprintf('DELETE FROM %s', IndexInfo::TABLE_NAME_TERMS_DOCUMENTS));
                $connection->executeStatement(\sprintf('DELETE FROM %s', IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS));
                $connection->executeStatement(\sprintf('DELETE FROM %s', IndexInfo::TABLE_NAME_DOCUMENTS));

                $this->reviseStorage(true);
            },
        );

        $this->commitChanges();
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): self
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return $this;
        }

        $this->recordChange(
            function () use ($ids): void {
                foreach (Util::arrayChunk(LoupeTypes::convertToArrayOfStrings($ids), self::MAX_IDS_PER_QUERY) as $chunk) {
                    $documentIds = $this->engine->getConnection()
                        ->executeQuery(
                            \sprintf('SELECT _id FROM %s WHERE _user_id IN(:ids)', IndexInfo::TABLE_NAME_DOCUMENTS),
                            [
                                'ids' => $chunk,
                            ],
                            [
                                'ids' => ArrayParameterType::STRING,
                            ],
                        )
                        ->fetchFirstColumn()
                    ;

                    $this->removeDocumentData(array_map(intval(...), $documentIds));

                    $this->engine->getConnection()
                        ->executeStatement(
                            \sprintf('DELETE FROM %s WHERE _user_id IN(:ids)', IndexInfo::TABLE_NAME_DOCUMENTS),
                            [
                                'ids' => $chunk,
                            ],
                            [
                                'ids' => ArrayParameterType::STRING,
                            ],
                        )
                    ;
                }

                $this->reviseStorage();
            },
        );

        $this->commitChanges();

        return $this;
    }

    public function recordChange(callable $change): void
    {
        $this->changes[] = $change;
    }

    public function discardPendingChanges(): void
    {
        $this->changes = [];
        $this->existingHashes = [];
        $this->orphanCandidateTermIds = [];
    }

    /**
     * @param non-empty-array<array<string, mixed>> $documents
     */
    private function addDocumentsFromArray(array $documents): void
    {
        $this->prepareIndex(reset($documents));

        foreach ($documents as $document) {
            $this->engine->getIndexInfo()->fixAndValidateDocument($document);
        }

        // Pre-load existing content hashes so unchanged documents can skip tokenization
        $this->existingHashes = $this->loadExistingHashes($documents);
        $documentsChanged = $this->indexPreparedDocuments($this->prepareDocumentArray($documents));
        $this->reviseStorageAfterIndexing($documentsChanged);
    }

    private function addDocumentsFromSource(DocumentSourceInterface $documents): void
    {
        if (!$this->validateDocumentSource($documents)) {
            return;
        }

        $documentsChanged = $this->indexPreparedDocuments($this->prepareDocumentSource($documents));
        $this->reviseStorageAfterIndexing($documentsChanged);
    }

    /**
     * @param array<string, mixed> $firstDocument
     */
    private function prepareIndex(array $firstDocument): void
    {
        // Prepare setup if needed
        if ($this->engine->getIndexInfo()->needsSetup()) {
            $this->engine->getIndexInfo()->setup($firstDocument);
        }

        // Migrate the data if needed
        if ($this->engine->needsReindex()) {
            $this->migrateDatabase($firstDocument);
        }
    }

    private function validateDocumentSource(DocumentSourceInterface $documents): bool
    {
        $hasDocuments = false;

        foreach ($documents as $document) {
            if (!$hasDocuments) {
                $this->prepareIndex($document);
                $hasDocuments = true;
            }

            $this->engine->getIndexInfo()->fixAndValidateDocument($document);
        }

        return $hasDocuments;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     *
     * @return \Generator<PreparedDocument>
     */
    private function prepareDocumentArray(array $documents): \Generator
    {
        foreach ($documents as $key => $document) {
            unset($documents[$key]);
            $preparedDocument = $this->prepareDocument($document);

            if ($this->documentChanged($preparedDocument)) {
                yield $preparedDocument;
            }
        }
    }

    /**
     * @return \Generator<PreparedDocument>
     */
    private function prepareDocumentSource(DocumentSourceInterface $documents): \Generator
    {
        $batch = [];

        foreach ($documents as $document) {
            $this->engine->getIndexInfo()->fixAndValidateDocument($document);
            $batch[] = $document;

            if (\count($batch) >= self::SOURCE_HASH_BATCH_SIZE) {
                yield from $this->prepareDocumentBatch($batch);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            yield from $this->prepareDocumentBatch($batch);
        }
    }

    /**
     * @param non-empty-array<array<string, mixed>> $documents
     *
     * @return \Generator<PreparedDocument>
     */
    private function prepareDocumentBatch(array $documents): \Generator
    {
        $this->existingHashes = $this->loadExistingHashes($documents);

        foreach ($documents as $document) {
            $preparedDocument = $this->prepareDocument($document);

            if ($this->documentChanged($preparedDocument)) {
                yield $preparedDocument;
            }
        }
    }

    /**
     * @param iterable<PreparedDocument> $documents
     *
     * @return bool Whether any document was written
     */
    private function indexPreparedDocuments(iterable $documents): bool
    {
        $documentsChanged = false;
        $batch = new PreparedDocumentCollection();

        foreach ($documents as $document) {
            $batch->add($document);

            if ($batch->getTermsCount() >= self::MAX_TERMS_PER_BATCH) {
                $documentsChanged = $this->processPreparedDocuments($batch) || $documentsChanged;
                $batch = new PreparedDocumentCollection();
            }
        }

        return $this->processPreparedDocuments($batch) || $documentsChanged;
    }

    /**
     * @return bool Whether any document was written
     */
    private function processPreparedDocuments(PreparedDocumentCollection $documents): bool
    {
        if ($documents->empty()) {
            return false;
        }

        $this->recordChange(
            function () use ($documents): void {
                $prepared = $this->bulkInsertDocuments($documents);
                $this->removeCurrentDocumentData($prepared);
                $this->bulkInsertMultiAttributes($prepared);
                $this->bulkInsertTerms($prepared);
            },
        );
        $this->commitChanges();

        return true;
    }

    private function documentChanged(PreparedDocument $document): bool
    {
        $userId = $document->getUserId();

        return !isset($this->existingHashes[$userId]) || $this->existingHashes[$userId] !== $document->getContentHash();
    }

    private function reviseStorageAfterIndexing(bool $documentsChanged): void
    {
        if ($documentsChanged) {
            $this->recordChange(
                function (): void {
                    $this->reviseStorage();
                },
            );
        }

        $this->commitChanges();
    }

    /**
     * Refresh SQLite's table statistics so the query planner can pick good join orders and indexes.
     * Without statistics, the planner misestimates the term_documents joins for queries with common. terms (e.g. "iron man").
     * The first build is always analyzed, afterwards the statistics are refreshed occasionally
     * Uses a full ANALYZE (analysis_limit=0) since a sampled ANALYZE (analysis_limit>0) still misleads the planner.
     */
    private function analyzeDatabase(): void
    {
        if (!$this->needsAnalyze()) {
            return;
        }

        try {
            $connection = $this->engine->getConnection();
            $connection->executeStatement('PRAGMA analysis_limit=0');
            $connection->executeStatement('ANALYZE');
        } catch (\Throwable) {
            // Ignore failures, analyze is pure optimization
        }
    }

    private function bulkInsertDocuments(PreparedDocumentCollection $preparedDocuments): PreparedDocumentCollection
    {
        $rowColumns = ['_user_id', '_document', '_hash'];
        $rows = [];

        foreach ($preparedDocuments->all() as $document) {
            $row = [$document->getUserId(), $document->getJsonDocument(), $document->getContentHash()];

            foreach ($document->getSingleAttributes() as $attribute) {
                $columnIndex = array_search($attribute->getName(), $rowColumns, true);

                if (false === $columnIndex) {
                    $rowColumns[] = $attribute->getName();
                    $row[] = $attribute->getValue();
                    continue;
                }
                $row[$columnIndex] = $attribute->getValue();
            }

            $rows[] = $row;
        }

        if ([] === $rows) {
            return new PreparedDocumentCollection();
        }

        $bulkUpsertConfig = BulkUpsertConfig::create(
            IndexInfo::TABLE_NAME_DOCUMENTS,
            $rowColumns,
            $rows,
            ['_user_id'],
            ConflictMode::Update,
        )
            // Enable change detection so we do not insert all the terms, prefixes, attributes etc. if the document did not
            // change at all (1:1 replacement -> noop).
            ->withChangeDetectingColumn('_hash')
            ->withReturningColumns(['_user_id', '_id'])
        ;

        $results = $this->engine->getBulkUpserterFactory()
            ->create($bulkUpsertConfig)
            ->execute()
        ;

        $mapper = BulkUpserter::convertResultsToKeyValueArray($results);
        $adjustedDocuments = new PreparedDocumentCollection();

        foreach ($preparedDocuments->all() as $document) {
            // Document not part of the RETURNING means there was no update because the _hash matched. We don't need
            // to do anything with that document then, it's unchanged.
            if (!isset($mapper[$document->getUserId()])) {
                continue;
            }

            $adjustedDocuments->add($document->withInternalId($mapper[$document->getUserId()]));
        }

        return $adjustedDocuments;
    }

    private function bulkInsertMultiAttributes(PreparedDocumentCollection $preparedDocuments): void
    {
        $documentsMapper = [];
        $stringRows = [];
        $numericRows = [];

        foreach ($preparedDocuments->all() as $document) {
            $documentsMapper[$document->getInternalId()] = [];

            foreach ($document->getMultiAttributes() as $attribute) {
                if (!isset($documentsMapper[$document->getInternalId()][$attribute->getName()])) {
                    $documentsMapper[$document->getInternalId()][$attribute->getName()] = [
                        'string_value' => [],
                        'numeric_value' => [],
                    ];
                }

                foreach ($attribute->getValues() as $value) {
                    if (\is_float($value) || \is_bool($value)) {
                        $documentsMapper[$document->getInternalId()][$attribute->getName()]['numeric_value'][] = (float) $value;
                        $numericRows[] = [$attribute->getName(), $value];
                    } else {
                        $documentsMapper[$document->getInternalId()][$attribute->getName()]['string_value'][] = $value;
                        $stringRows[] = [$attribute->getName(), $value];
                    }
                }
            }
        }

        $documentIdsToAttributeIdsMapper = [];

        /**
         * @param non-empty-list<array<mixed>> $rows
         */
        $bulkInsert = function (string $columnName, array $rows, array $documentsMapper, array &$documentIdsToAttributeIdsMapper): void {
            if ([] === $rows || !array_is_list($rows)) {
                return;
            }

            $results = $this->engine->getBulkUpserterFactory()
                ->create(BulkUpsertConfig::create(
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    ['attribute', $columnName],
                    $rows,
                    ['attribute', $columnName],
                    ConflictMode::Ignore,
                )->withReturningColumns(['id', 'attribute', $columnName]))
                ->execute()
            ;

            $resultMapper = [];

            foreach (BulkUpserter::convertResultsToIndexedArray($results, 'id') as $attributeId => $row) {
                $resultMapper[$row['attribute']][json_encode($row[$columnName])] = $attributeId;
            }

            foreach ($documentsMapper as $documentId => $documentData) {
                foreach ($documentData as $attributeName => $attributeData) {
                    foreach ($attributeData[$columnName] as $value) {
                        if (!isset($resultMapper[$attributeName][json_encode($value)])) {
                            throw new IndexException('Could not map attribute '.$attributeName.' to '.$value.'. This should not happen.');
                        }

                        $documentIdsToAttributeIdsMapper[$documentId][] = $resultMapper[$attributeName][json_encode($value)];
                    }
                }
            }
        };

        // Bulk insert string and numeric values separately
        $bulkInsert('string_value', $stringRows, $documentsMapper, $documentIdsToAttributeIdsMapper);
        $bulkInsert('numeric_value', $numericRows, $documentsMapper, $documentIdsToAttributeIdsMapper);

        // Now bulk insert the relations to the documents
        $rows = [];

        foreach ($documentIdsToAttributeIdsMapper as $documentId => $attributeIds) {
            foreach ($attributeIds as $attributeId) {
                $rows[] = [$attributeId, $documentId];
            }
        }

        if ([] === $rows) {
            return;
        }

        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                ['attribute', 'document'],
                $rows,
                ['attribute', 'document'],
                ConflictMode::Ignore,
            ))
            ->execute()
        ;
    }

    /**
     * @param array<string|int, array<int>> $prefixRelevantTerms An array of terms as key and matching document IDs as value
     * @param array<string, int>            $termsIdMapper       An
     */
    private function bulkInsertPrefixTerms(array $prefixRelevantTerms, array $termsIdMapper): void
    {
        if ([] === $prefixRelevantTerms) {
            return;
        }

        $prefixToTermMapper = [];
        $termsLengthCache = [];
        $rows = [];

        // Generate prefixes
        foreach ($prefixRelevantTerms as $term => $documentIds) {
            $term = (string) $term; // Unfortunately PHP auto-converts string numbers to integers ('1234' -> 1234)

            $chars = mb_str_split($term, 1, 'UTF-8');
            // We don't need to index anything after our max index length because for prefix search, we do not have
            // to index the entire word as there's no such thing as exact match or phrase search.
            $chars = \array_slice($chars, 0, $this->engine->getConfiguration()->getTypoTolerance()->getIndexLength());

            $prefix = [];

            for ($i = 0; $i < \count($chars); ++$i) {
                $prefix[] = $chars[$i];

                // First n characters can be skipped as they are not relevant for prefix search
                if ($i < $this->engine->getConfiguration()->getMinTokenLengthForPrefixSearch() - 1) {
                    continue;
                }

                // The entire word does not need to be indexed again either
                if (\count($prefix) === \count($chars)) {
                    continue;
                }

                $asString = implode('', $prefix);

                if (!isset($termsLengthCache[$asString])) {
                    $termsLengthCache[$asString] = mb_strlen($asString, 'UTF-8');
                }

                $rows[] = [$asString, $termsLengthCache[$asString], 0];

                if (!isset($termsIdMapper[$term])) {
                    throw new IndexException('Could not find term '.$term.'. This should not happen.');
                }

                $prefixToTermMapper[$asString][] = $termsIdMapper[$term];
            }
        }

        if ([] === $rows) {
            return;
        }

        // States
        if (!$this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
            $allStates = $this->engine->getStateSetIndex()->index(array_map(static fn (array $row) => $row[0], $rows));

            foreach ($rows as $i => $row) {
                if (!isset($allStates[$row[0]])) {
                    throw new IndexException('Could not find state for prefix. This should not happen.');
                }
                $rows[$i][2] = $allStates[$row[0]];
            }
        }

        // Bulk insert prefixes
        $results = $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_PREFIXES,
                ['prefix', 'length', 'state'],
                $rows,
                ['prefix', 'state', 'length'],
                ConflictMode::Ignore,
            )->withReturningColumns(['prefix', 'id']))
            ->execute()
        ;

        $prefixIdMapper = BulkUpserter::convertResultsToKeyValueArray($results);
        $relationRows = [];

        foreach ($prefixToTermMapper as $prefix => $termIds) {
            if (!isset($prefixIdMapper[$prefix])) {
                throw new IndexException('Could not find prefix '.$prefix.'. This should not happen.');
            }

            foreach ($termIds as $termId) {
                $relationRows[] = [$prefixIdMapper[$prefix], $termId];
            }
        }

        if ([] === $relationRows) {
            return;
        }

        // Now bulk insert the relations to the terms
        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_PREFIXES_TERMS,
                ['prefix', 'term'],
                $relationRows,
                ['prefix', 'term'],
                ConflictMode::Ignore,
            ))
            ->execute()
        ;
    }

    private function bulkInsertTerms(PreparedDocumentCollection $preparedDocuments): void
    {
        foreach ($preparedDocuments->chunkByNumberOfTerms(self::MAX_TERMS_PER_BATCH) as $batch) {
            $this->processTermBatch($batch);
        }
    }

    /**
     * @return array<string|int, array<int>>
     */
    private function collectPrefixRelevantTerms(PreparedDocumentCollection $documents): array
    {
        if (!$this->engine->getConfiguration()->getTypoTolerance()->isEnabledForPrefixSearch()) {
            return [];
        }

        $prefixRelevantTerms = [];

        foreach ($documents->all() as $document) {
            foreach ($document->getTerms() as $term) {
                // Prefix relevant terms must not be variants
                if (!$term->isVariant()) {
                    $prefixRelevantTerms[$term->getTerm()][] = $document->getInternalId();
                }
            }
        }

        return $prefixRelevantTerms;
    }

    /**
     * @return list<array{string, int, int}>
     */
    private function collectTermRows(PreparedDocumentCollection $documents): array
    {
        $knownTerms = [];
        $rows = [];

        foreach ($documents->all() as $document) {
            foreach ($document->getTerms() as $term) {
                if (isset($knownTerms[$term->getTerm()])) {
                    continue;
                }

                $knownTerms[$term->getTerm()] = true;
                $rows[] = [$term->getTerm(), mb_strlen($term->getTerm(), 'UTF-8'), 0];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, int> $termsIdMapper
     *
     * @return list<array{int, string, int, int, int, bool, int}>
     */
    private function collectTermDocumentRows(PreparedDocumentCollection $documents, array $termsIdMapper): array
    {
        $rowsByTerm = [];

        foreach ($documents->all() as $document) {
            foreach ($document->getTerms() as $term) {
                if (!isset($termsIdMapper[$term->getTerm()])) {
                    throw new IndexException('Could not find term '.$term->getTerm().'. This should not happen.');
                }

                $rowsByTerm[$term->getTerm()][] = [$document->getInternalId(), $term->getAttribute(), $term->getPosition(), $term->getStart(), $term->getEnd(), $term->isVariant(), $termsIdMapper[$term->getTerm()]];
            }
        }

        $rows = [];

        // Keep relations grouped by term so SQLite can update its term-first indexes with minimal B-tree churn.
        foreach ($rowsByTerm as $term => $termRows) {
            foreach ($termRows as $row) {
                $rows[] = $row;
            }

            unset($rowsByTerm[$term]);
        }

        return $rows;
    }

    /**
     * @param non-empty-list<array{string, int, int}> $rows
     *
     * @return array<string, int>
     */
    private function insertTermRows(array $rows): array
    {
        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_TERMS,
                ['term', 'length', 'state'],
                $rows,
                ['term', 'state', 'length'],
                ConflictMode::Ignore,
            ))
            ->execute()
        ;

        $termsIdMapper = [];

        foreach (Util::arrayChunk(array_column($rows, 0), self::MAX_IDS_PER_QUERY) as $terms) {
            $results = $this->engine->getConnection()->executeQuery(
                \sprintf('SELECT term, id FROM %s WHERE term IN (?)', IndexInfo::TABLE_NAME_TERMS),
                [$terms],
                [ArrayParameterType::STRING],
            )->fetchAllKeyValue();

            foreach ($results as $term => $id) {
                $termsIdMapper[(string) $term] = (int) $id;
            }
        }

        return $termsIdMapper;
    }

    /**
     * @param list<array{int, string, int, int, int, bool, int}> $rows
     */
    private function insertTermDocumentRows(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
                ['document', 'attribute', 'position', 'start', 'end', 'folded', 'term'],
                $rows,
                ['term', 'document', 'attribute', 'position', 'folded'],
                ConflictMode::Ignore,
            ))
            ->execute()
        ;
    }

    /**
     * @param non-empty-list<array{string, int, int}> $rows
     *
     * @return non-empty-list<array{string, int, int}>
     */
    private function populateTermStates(array $rows): array
    {
        if ($this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
            return $rows;
        }

        $allStates = $this->engine->getStateSetIndex()->index(array_column($rows, 0));

        foreach ($rows as $i => $row) {
            if (!isset($allStates[$row[0]])) {
                throw new IndexException('Could not find state for term. This should not happen.');
            }
            $rows[$i][2] = $allStates[$row[0]];
        }

        return $rows;
    }

    private function processTermBatch(PreparedDocumentCollection $documents): void
    {
        $rows = $this->collectTermRows($documents);

        if ([] === $rows) {
            return;
        }

        $termsIdMapper = $this->insertTermRows($this->populateTermStates($rows));
        $this->insertTermDocumentRows($this->collectTermDocumentRows($documents, $termsIdMapper));
        $this->bulkInsertPrefixTerms($this->collectPrefixRelevantTerms($documents), $termsIdMapper);
    }

    private function commitChanges(): void
    {
        // Wait for our process to acquire the lock
        $this->ticketHandler->acquire();

        // Apply changes one by one
        foreach ($this->changes as $change) {
            $change();
        }

        // Reset changes
        $this->changes = [];
    }

    /**
     * @param array<array<string, mixed>> $documents
     *
     * @return array<string, string>
     */
    private function loadExistingHashes(array $documents): array
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return [];
        }

        $primaryKey = $this->engine->getConfiguration()->getPrimaryKey();
        $userIds = [];

        foreach ($documents as $document) {
            if (isset($document[$primaryKey])) {
                $userIds[(string) $document[$primaryKey]] = true;
            }
        }

        if ([] === $userIds) {
            return [];
        }

        $hashes = [];

        foreach (Util::arrayChunk(array_keys($userIds), self::MAX_IDS_PER_QUERY) as $chunk) {
            $rows = $this->engine->getConnection()->executeQuery(
                \sprintf('SELECT _user_id, _hash FROM %s WHERE _user_id IN (?)', IndexInfo::TABLE_NAME_DOCUMENTS),
                [$chunk],
                [ArrayParameterType::STRING],
            )->fetchAllKeyValue();

            foreach ($rows as $userId => $hash) {
                $hashes[(string) $userId] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function migrateDatabase(array $document): void
    {
        $schemaManager = $this->engine->getConnection()->createSchemaManager();

        if (!$schemaManager->tablesExist([IndexInfo::TABLE_NAME_DOCUMENTS])) {
            throw new IndexException('Could not automatically migrate your database because the documents table does not exist. This should not happen.');
        }

        /** @phpstan-ignore-next-line */
        $table = method_exists($schemaManager, 'introspectTableByUnquotedName') ?
            $schemaManager->introspectTableByUnquotedName(IndexInfo::TABLE_NAME_DOCUMENTS) :
            $schemaManager->introspectTable(IndexInfo::TABLE_NAME_DOCUMENTS);

        $documentColumn = null;

        // As of 0.13 this is "_document", before it was "document"
        foreach (['_document', 'document'] as $candidate) {
            if ($table->hasColumn($candidate)) {
                $documentColumn = $candidate;
                break;
            }
        }

        if (null === $documentColumn) {
            throw new IndexException('Could not automatically migrate your database because the document column does not exist. This should not happen.');
        }

        $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS documents_migration');
        $this->engine->getConnection()->executeStatement('CREATE TABLE documents_migration AS SELECT '.$documentColumn.' FROM documents;');

        foreach ($this->engine->getIndexInfo()->getAllTableNames() as $tableName) {
            $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS '.$tableName);
        }

        $this->engine->getIndexInfo()->reset();
        $this->engine->getIndexInfo()->setup($document);

        $chunk = [];

        foreach ($this->engine->getConnection()->executeQuery('SELECT '.$documentColumn.' FROM documents_migration')
            ->iterateAssociative() as $row) {
            $chunk[] = json_decode($row[$documentColumn], true);

            if (\count($chunk) >= 100) {
                $this->addDocuments($chunk);
                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            $this->addDocuments($chunk);
        }

        $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS documents_migration');
    }

    private function needsAnalyze(): bool
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return false;
        }

        // Always analyze when statistics are missing (usually after initial bulk insert)
        $hasStats = (bool) $this->engine->getConnection()
            ->executeQuery("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sqlite_stat1'")
            ->fetchOne()
        ;

        if (!$hasStats) {
            return true;
        }

        // Otherwise analyze only occasionally, reusing vacuum probability
        return random_int(1, 100) <= $this->engine->getConfiguration()->getVacuumProbability();
    }

    private function needsVacuum(): bool
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return false;
        }

        // Check against configured vacuum probability
        return random_int(1, 100) <= $this->engine->getConfiguration()->getVacuumProbability();
    }

    private function persistStateSet(): void
    {
        if ($this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
            return;
        }

        /** @var StateSet $stateSet */
        $stateSet = $this->engine->getStateSetIndex()->getStateSet();
        $stateSet->persist();
    }

    /**
     * @param array<string, mixed> $document
     */
    private function prepareDocument(array $document): PreparedDocument
    {
        $primaryKey = $this->engine->getConfiguration()->getPrimaryKey();

        if ($this->engine->getConfiguration()->getDisplayedAttributes() !== ['*']) {
            $documentData = array_intersect_key($document, array_flip($this->engine->getConfiguration()->getDisplayedAttributes()));
        } else {
            $documentData = $document;
        }

        // Keep the primary key in persisted document data so reindex/migration can always rehydrate documents.
        $documentData[$primaryKey] = $document[$primaryKey];
        $userId = (string) $document[$primaryKey];

        $preparedDocument = new PreparedDocument(
            $userId,
            Util::encodeJson($documentData),
        );

        // Terms and attributes of unchanged documents are excluded by the SQL change detection: skip expensive tokenization & attribute extraction
        if (isset($this->existingHashes[$userId]) && $this->existingHashes[$userId] === $preparedDocument->getContentHash()) {
            return $preparedDocument;
        }

        $singleAttributes = [];
        $multiAttributes = [];

        foreach ($this->engine->getIndexInfo()->getSingleFilterableAndSortableAttributes() as $attribute) {
            if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                continue;
            }

            $loupeType = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute);

            if (LoupeTypes::TYPE_GEO === $loupeType) {
                $singleAttributes[] = new SingleAttribute($attribute.'_geo_lat', isset($document[$attribute]['lat']) ? LoupeTypes::convertToFloat($document[$attribute]['lat']) : LoupeTypes::TYPE_NULL);
                $singleAttributes[] = new SingleAttribute($attribute.'_geo_lng', isset($document[$attribute]['lng']) ? LoupeTypes::convertToFloat($document[$attribute]['lng']) : LoupeTypes::TYPE_NULL);
                continue;
            }

            $value = LoupeTypes::convertValueToType($document[$attribute] ?? null, $loupeType);

            if (!\is_scalar($value)) {
                throw new \LogicException('This should not happen.');
            }

            $singleAttributes[] = new SingleAttribute($attribute, $value);
        }

        // Markers for IS EMPTY and IS NULL filters on multi attributes
        foreach ($this->engine->getIndexInfo()->getMultiFilterableAttributes() as $attribute) {
            $loupeType = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute);
            $value = LoupeTypes::convertValueToType($document[$attribute] ?? null, $loupeType);

            if (\is_array($value)) {
                $singleAttributes[] = new SingleAttribute($attribute, \count($value));
            } else {
                $singleAttributes[] = new SingleAttribute($attribute, $value);
            }
        }

        foreach ($this->engine->getIndexInfo()->getMultiFilterableAttributes() as $attribute) {
            $attributeValue = $document[$attribute] ?? null;

            $convertedValue = LoupeTypes::convertValueToType(
                $attributeValue,
                $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute),
            );

            if (\is_bool($convertedValue)) {
                throw new \LogicException('This should not happen.');
            }

            $multiAttributes[] = new MultiAttribute($attribute, (array) $convertedValue);
        }

        // Terms
        $terms = [];
        $cleanedDocument = [];
        $searchableAttributes = $this->engine->getConfiguration()->getSearchableAttributes();

        foreach ($document as $attributeName => $attributeValue) {
            if (['*'] !== $searchableAttributes && !\in_array($attributeName, $searchableAttributes, true)) {
                continue;
            }

            $cleanedDocument[$attributeName] = LoupeTypes::convertToString($attributeValue);
        }

        $tokensPerAttribute = $this->engine->getTokenizer()->tokenizeDocument($cleanedDocument);

        $termPosition = 1;

        foreach ($tokensPerAttribute as $attributeName => $tokenCollection) {
            foreach ($tokenCollection->all() as $token) {
                // Index the main term
                $terms[] = new Term($token->getTerm(), $attributeName, $termPosition, $token->getOriginalStartPosition(), $token->getOriginalEndPosition(), $token->wasFolded());

                // Index variants
                foreach ($token->getVariants() as $termVariant) {
                    $terms[] = new Term($termVariant, $attributeName, $termPosition, $token->getOriginalStartPosition(), $token->getOriginalEndPosition(), true);
                }

                ++$termPosition;
            }
        }

        return $preparedDocument
            ->withSingleAttributes($singleAttributes)
            ->withMultiAttributes($multiAttributes)
            ->withTerms($terms)
        ;
    }

    private function removeCurrentDocumentData(PreparedDocumentCollection $preparedDocuments): void
    {
        $this->removeDocumentData($preparedDocuments->allInternalIds());
    }

    /**
     * @param array<int> $documentIds
     */
    private function removeDocumentData(array $documentIds): void
    {
        $connection = $this->engine->getConnection();

        foreach (Util::arrayChunk($documentIds, self::MAX_IDS_PER_QUERY) as $chunk) {
            $termIds = $connection->executeQuery(
                \sprintf('SELECT DISTINCT term FROM %s WHERE document IN (?)', IndexInfo::TABLE_NAME_TERMS_DOCUMENTS),
                [$chunk],
                [ArrayParameterType::INTEGER],
            )->iterateColumn();

            foreach ($termIds as $termId) {
                $this->orphanCandidateTermIds[(int) $termId] = true;
            }

            foreach ([IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS] as $table) {
                $connection->executeStatement(
                    \sprintf('DELETE FROM %s WHERE document IN (?)', $table),
                    [$chunk],
                    [ArrayParameterType::INTEGER],
                );
            }
        }
    }

    /**
     * @return array<int>
     */
    private function findOrphanedDocumentIds(): array
    {
        $connection = $this->engine->getConnection();

        // Walk the distinct documents of the relation index with one seek each instead of reading every relation
        $termsDocuments = $connection->executeQuery(\sprintf(
            'WITH RECURSIVE relation(document) AS (
                SELECT MIN(document) FROM %1$s
                UNION ALL
                SELECT (SELECT MIN(document) FROM %1$s WHERE document > relation.document) FROM relation WHERE relation.document IS NOT NULL
            )
            SELECT document FROM relation WHERE document IS NOT NULL AND document NOT IN (SELECT _id FROM %2$s)',
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        ))->fetchFirstColumn();

        $multiAttributesDocuments = $connection->executeQuery(\sprintf(
            'SELECT DISTINCT document FROM %s WHERE document NOT IN (SELECT _id FROM %s)',
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        ))->fetchFirstColumn();

        return array_values(array_unique(array_map(intval(...), [...$termsDocuments, ...$multiAttributesDocuments])));
    }

    /**
     * @param array<int>|null $candidateIds
     *
     * @return \Generator<array<list<mixed>>>
     */
    private function findOrphans(string $query, array|null $candidateIds): \Generator
    {
        $connection = $this->engine->getConnection();

        if (null === $candidateIds) {
            yield from Util::arrayChunk($connection->executeQuery($query)->fetchAllNumeric(), self::MAX_IDS_PER_QUERY);

            return;
        }

        foreach (Util::arrayChunk($candidateIds, self::MAX_IDS_PER_QUERY) as $chunk) {
            yield $connection->executeQuery($query.' AND id IN (?)', [$chunk], [ArrayParameterType::INTEGER])->fetchAllNumeric();
        }
    }

    /**
     * @param array<int> $termIds
     *
     * @return array<int> The prefixes which lost relations
     */
    private function removePrefixRelations(array $termIds): array
    {
        $connection = $this->engine->getConnection();
        $prefixIds = [];

        foreach (Util::arrayChunk($termIds, self::MAX_IDS_PER_QUERY) as $chunk) {
            $chunkPrefixIds = $connection->executeQuery(
                \sprintf('SELECT DISTINCT prefix FROM %s WHERE term IN (?)', IndexInfo::TABLE_NAME_PREFIXES_TERMS),
                [$chunk],
                [ArrayParameterType::INTEGER],
            )->iterateColumn();

            foreach ($chunkPrefixIds as $prefixId) {
                $prefixIds[(int) $prefixId] = true;
            }

            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE term IN (?)', IndexInfo::TABLE_NAME_PREFIXES_TERMS),
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
        }

        return array_keys($prefixIds);
    }

    private function removeOrphans(bool $checkEntireIndex): void
    {
        if ($checkEntireIndex) {
            $this->removeDocumentData($this->findOrphanedDocumentIds());
        }

        $candidateTermIds = $checkEntireIndex ? null : array_keys($this->orphanCandidateTermIds);
        $this->orphanCandidateTermIds = [];

        $removedTermIds = $this->removeOrphansAmong(
            IndexInfo::TABLE_NAME_TERMS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            'term',
            $candidateTermIds,
        );

        // Prefixes only lose relations when their terms are removed
        if ($checkEntireIndex) {
            $this->engine->getConnection()->executeStatement(\sprintf(
                'DELETE FROM %s WHERE term NOT IN (SELECT id FROM %s)',
                IndexInfo::TABLE_NAME_PREFIXES_TERMS,
                IndexInfo::TABLE_NAME_TERMS,
            ));
            $candidatePrefixIds = null;
        } else {
            $candidatePrefixIds = $this->removePrefixRelations($removedTermIds);
        }

        $this->removeOrphansAmong(
            IndexInfo::TABLE_NAME_PREFIXES,
            IndexInfo::TABLE_NAME_PREFIXES_TERMS,
            'prefix',
            $candidatePrefixIds,
        );
    }

    /**
     * @param array<int>|null $candidateIds The rows to check, null to check all
     *
     * @return array<int> The removed ids
     */
    private function removeOrphansAmong(string $table, string $relationTable, string $column, array|null $candidateIds): array
    {
        $connection = $this->engine->getConnection();
        $query = \sprintf('SELECT id, %2$s FROM %1$s WHERE id NOT IN (SELECT %2$s FROM %3$s)', $table, $column, $relationTable);
        $stateSetIndex = $this->engine->getStateSetIndex();
        $removedIds = [];

        foreach ($this->findOrphans($query, $candidateIds) as $orphans) {
            if ([] === $orphans) {
                continue;
            }

            $stateSetIndex->removeFromIndex(array_column($orphans, 1));

            $orphanIds = array_map(intval(...), array_column($orphans, 0));
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE id IN (?)', $table),
                [$orphanIds],
                [ArrayParameterType::INTEGER],
            );

            array_push($removedIds, ...$orphanIds);
        }

        return $removedIds;
    }

    /**
     * @param bool $checkEntireIndex Check the entire index for orphans instead of only the data currently touched
     */
    private function reviseStorage(bool $checkEntireIndex = false): void
    {
        $runMaintenance = $this->needsVacuum();

        $this->removeOrphans($checkEntireIndex || $runMaintenance);
        $this->persistStateSet();

        if ($runMaintenance) {
            $this->engine->getConnection()->executeStatement('PRAGMA incremental_vacuum');
        }

        $this->analyzeDatabase();
    }
}
