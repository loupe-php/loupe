<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Doctrine\DBAL\ArrayParameterType;
use Loupe\Loupe\Exception\IndexException;
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
     * tests have shown a good result with 2000 terms, but we might want to make this configurable one day.
     * However, it's also a bit hard to document and understand so for now, let's keep this internal.
     */
    private const MAX_TERMS_PER_BATCH = 2000;

    /**
     * @var array<int, callable>
     */
    private array $changes = [];

    public function __construct(
        private Engine $engine,
        private TicketHandler $ticketHandler
    ) {
    }

    /**
     * @param non-empty-array<array<string,mixed>> $documents
     */
    public function addDocuments(array $documents): void
    {
        $firstDocument = reset($documents);

        // Prepare setup if needed
        if ($this->engine->getIndexInfo()->needsSetup()) {
            $this->engine->getIndexInfo()->setup($firstDocument);
        }

        // Migrate the data if needed
        if ($this->engine->needsReindex()) {
            $this->migrateDatabase();
        }

        // Fix, validate and record schema updates if needed
        foreach ($documents as $document) {
            $this->engine->getIndexInfo()->fixAndValidateDocument($document);
        }

        $processBatch = function (PreparedDocumentCollection $preparedDocuments): void {
            if ($preparedDocuments->empty()) {
                return;
            }

            $this->recordChange(function () use ($preparedDocuments) {
                $prepared = $this->bulkInsertDocuments($preparedDocuments);
                $this->removeCurrentDocumentData($prepared);
                $this->bulkInsertMultiAttributes($prepared);
                $this->bulkInsertTerms($prepared);
            });

            $this->commitChanges();
        };

        // Now index the documents in chunks as preparing too many documents and keeping it all in memory before
        // inserting would result in too much memory usage.
        while (!empty($documents)) {
            $preparedDocuments = new PreparedDocumentCollection();

            foreach ($documents as $k => $document) {
                $preparedDocuments->add($this->prepareDocument($document));
                unset($documents[$k]);

                if ($preparedDocuments->getTermsCount() >= self::MAX_TERMS_PER_BATCH) {
                    break;
                }
            }

            $processBatch($preparedDocuments);
        }

        // Finally, revise storage once
        $this->recordChange(function () {
            $this->reviseStorage();
        });
        $this->commitChanges();
    }

    public function deleteAllDocuments(): void
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return;
        }

        $this->recordChange(function () {
            $this->engine->getConnection()->executeStatement(\sprintf('DELETE FROM %s', IndexInfo::TABLE_NAME_DOCUMENTS));

            $this->reviseStorage();
        });

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

        $this->recordChange(function () use ($ids): void {
            $this->engine->getConnection()
                ->executeStatement(
                    \sprintf('DELETE FROM %s WHERE _user_id IN(:ids)', IndexInfo::TABLE_NAME_DOCUMENTS),
                    [
                        'ids' => LoupeTypes::convertToArrayOfStrings($ids),
                    ],
                    [
                        'ids' => ArrayParameterType::STRING,
                    ]
                );

            $this->reviseStorage();
        });

        $this->commitChanges();

        return $this;
    }

    public function recordChange(callable $change): void
    {
        $this->changes[] = $change;
    }

    private function bulkInsertDocuments(PreparedDocumentCollection $preparedDocuments): PreparedDocumentCollection
    {
        $rowColumns = ['_user_id', '_document', '_hash'];
        $rows = [];
        foreach ($preparedDocuments->all() as $document) {
            $row = [$document->getUserId(), $document->getJsonDocument(), $document->getContentHash()];

            foreach ($document->getSingleAttributes() as $attribute) {
                $columnIndex = array_search($attribute->getName(), $rowColumns, true);

                if ($columnIndex === false) {
                    $rowColumns[] = $attribute->getName();
                    $row[] = $attribute->getValue();
                    continue;
                }
                $row[$columnIndex] = $attribute->getValue();
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return new PreparedDocumentCollection();
        }

        $bulkUpsertConfig = BulkUpsertConfig::create(
            IndexInfo::TABLE_NAME_DOCUMENTS,
            $rowColumns,
            $rows,
            ['_user_id'],
            ConflictMode::Update
        )
            // Enable change detection so we do not insert all the terms, prefixes, attributes etc. if the document did not
            // change at all (1:1 replacement -> noop).
            ->withChangeDetectingColumn('_hash')
            ->withReturningColumns(['_user_id', '_id'])
        ;

        $results = $this->engine->getBulkUpserterFactory()
            ->create($bulkUpsertConfig)
            ->execute();

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
        $bulkInsert = function (string $columnName, array $rows, array $documentsMapper, array &$documentIdsToAttributeIdsMapper) {
            if ($rows === [] || !array_is_list($rows)) {
                return;
            }

            $results = $this->engine->getBulkUpserterFactory()
                ->create(BulkUpsertConfig::create(
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    ['attribute', $columnName],
                    $rows,
                    ['attribute', $columnName],
                    ConflictMode::Ignore
                )->withReturningColumns(['id', 'attribute', $columnName]))
                ->execute();

            $resultMapper = [];
            foreach (BulkUpserter::convertResultsToIndexedArray($results, 'id') as $attributeId => $row) {
                $resultMapper[$row['attribute']][json_encode($row[$columnName])] = $attributeId;
            }

            foreach ($documentsMapper as $documentId => $documentData) {
                foreach ($documentData as $attributeName => $attributeData) {
                    foreach ($attributeData[$columnName] as $value) {
                        if (!isset($resultMapper[$attributeName][json_encode($value)])) {
                            throw new IndexException('Could not map attribute ' . $attributeName . ' to ' . $value . '. This should not happen.');
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

        if ($rows === []) {
            return;
        }

        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
                ['attribute', 'document'],
                $rows,
                ['attribute', 'document'],
                ConflictMode::Ignore
            ))
            ->execute();
    }

    /**
     * @param array<string|int, array<int>> $prefixRelevantTerms An array of terms as key and matching document IDs as value
     * @param array<string, int> $termsIdMapper An
     */
    private function bulkInsertPrefixTerms(array $prefixRelevantTerms, array $termsIdMapper): void
    {
        if ($prefixRelevantTerms === []) {
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
            for ($i = 0; $i < \count($chars); $i++) {
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
                    throw new IndexException('Could not find term ' . $term . '. This should not happen.');
                }

                $prefixToTermMapper[$asString][] = $termsIdMapper[$term];
            }
        }

        if ($rows === []) {
            return;
        }

        // States
        if (!$this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
            $allStates = $this->engine->getStateSetIndex()->index(array_map(function (array $row) {
                return $row[0];
            }, $rows));

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
                ConflictMode::Ignore
            )->withReturningColumns(['prefix', 'id']))
            ->execute();

        $prefixIdMapper = BulkUpserter::convertResultsToKeyValueArray($results);
        $relationRows = [];

        foreach ($prefixToTermMapper as $prefix => $termIds) {
            if (!isset($prefixIdMapper[$prefix])) {
                throw new IndexException('Could not find prefix ' . $prefix . '. This should not happen.');
            }

            foreach ($termIds as $termId) {
                $relationRows[] = [$prefixIdMapper[$prefix], $termId];
            }
        }

        if ($relationRows === []) {
            return;
        }

        // Now bulk insert the relations to the terms
        $this->engine->getBulkUpserterFactory()
            ->create(BulkUpsertConfig::create(
                IndexInfo::TABLE_NAME_PREFIXES_TERMS,
                ['prefix', 'term'],
                $relationRows,
                ['prefix', 'term'],
                ConflictMode::Ignore
            ))
            ->execute();

    }

    private function bulkInsertTerms(PreparedDocumentCollection $preparedDocuments): void
    {
        $processBatch = function (PreparedDocumentCollection $preparedDocuments): void {
            if ($preparedDocuments->empty()) {
                return;
            }

            // Key is the term, 0 the "document" (id), 1 the "attribute" (as string), 2 the "position" - need to optimize for memory here
            $termsMapper = [];
            // 0 is the "term" (as string), 1 the "length", 2 the "state" - need to optimize for memory here
            $rows = [];
            $prefixRelevantTerms = [];
            $indexPrefixes = $this->engine->getConfiguration()->getTypoTolerance()->isEnabledForPrefixSearch();

            foreach ($preparedDocuments->all() as $document) {
                foreach ($document->getTerms() as $term) {
                    $rows[] = [$term->getTerm(), $term->getTermLength(), 0];
                    $termsMapper[$term->getTerm()][] = [$document->getInternalId(), $term->getAttribute(), $term->getPosition()];

                    // Prefix relevant terms must not be variants
                    if ($indexPrefixes && !$term->isVariant()) {
                        $prefixRelevantTerms[$term->getTerm()][] = $document->getInternalId();
                    }
                }
            }

            if ($rows === []) {
                return;
            }

            // States
            if (!$this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
                $allStates = $this->engine->getStateSetIndex()->index(array_map(function (array $row) {
                    return $row[0];
                }, $rows));

                foreach ($rows as $i => $row) {
                    if (!isset($allStates[$row[0]])) {
                        throw new IndexException('Could not find state for term. This should not happen.');
                    }
                    $rows[$i][2] = $allStates[$row[0]];
                }
            }

            // Bulk insert terms
            $relationRows = [];
            $results = $this->engine->getBulkUpserterFactory()
                ->create(BulkUpsertConfig::create(
                    IndexInfo::TABLE_NAME_TERMS,
                    ['term', 'length', 'state'],
                    $rows,
                    ['term', 'state', 'length'],
                    ConflictMode::Ignore
                )->withReturningColumns(['term', 'id']))
                ->execute();

            $termsIdMapper = BulkUpserter::convertResultsToKeyValueArray($results);
            foreach ($termsMapper as $term => $occurrences) {
                if (!isset($termsIdMapper[$term])) {
                    throw new IndexException('Could not find term ' . $term . '. This should not happen.');
                }

                foreach ($occurrences as $occurrence) {
                    $relationRows[] = [...$occurrence, $termsIdMapper[$term]];
                }
            }

            if ($relationRows === []) {
                return;
            }

            // Now bulk insert the relations to the documents
            $this->engine->getBulkUpserterFactory()
                ->create(BulkUpsertConfig::create(
                    IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
                    ['document', 'attribute', 'position', 'term'],
                    $relationRows,
                    ['term', 'document', 'attribute', 'position'],
                    ConflictMode::Ignore
                ))
                ->execute();

            // Index prefixes if needed
            $this->bulkInsertPrefixTerms($prefixRelevantTerms, $termsIdMapper);
        };

        foreach ($preparedDocuments->chunkByNumberOfTerms(self::MAX_TERMS_PER_BATCH) as $batch) {
            $processBatch($batch);
        }
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

    private function migrateDatabase(): void
    {
        $schemaManager = $this->engine->getConnection()->createSchemaManager();
        if (!$schemaManager->tableExists(IndexInfo::TABLE_NAME_DOCUMENTS)) {
            throw new IndexException('Could not automatically migrate your database because the documents table does not exist. This should not happen.');
        }

        $table = $schemaManager->introspectTableByUnquotedName(IndexInfo::TABLE_NAME_DOCUMENTS);
        $documentColumn = null;

        // As of 0.13 this is "_document", before it was "document"
        foreach (['_document', 'document'] as $candidate) {
            if ($table->hasColumn($candidate)) {
                $documentColumn = $candidate;
                break;
            }
        }

        if ($documentColumn === null) {
            throw new IndexException('Could not automatically migrate your database because the document column does not exist. This should not happen.');
        }

        $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS documents_migration');
        $this->engine->getConnection()->executeStatement('CREATE TABLE documents_migration AS SELECT ' . $documentColumn . ' FROM documents;');

        foreach ($this->engine->getIndexInfo()->getAllTableNames() as $tableName) {
            $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS ' . $tableName);
        }

        $this->engine->getIndexInfo()->reset();

        $chunk = [];

        foreach ($this->engine->getConnection()->executeQuery('SELECT ' . $documentColumn . ' FROM documents_migration')
            ->iterateAssociative() as $row) {
            $chunk[] = json_decode($row[$documentColumn], true);

            if (\count($chunk) >= 100) {
                $this->addDocuments($chunk);
            }
        }

        if ($chunk !== []) {
            $this->addDocuments($chunk);
        }

        $this->engine->getConnection()->executeStatement('DROP TABLE IF EXISTS documents_migration');
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
        if ($this->engine->getConfiguration()->getDisplayedAttributes() !== ['*']) {
            $documentData = array_intersect_key($document, array_flip($this->engine->getConfiguration()->getDisplayedAttributes()));
        } else {
            $documentData = $document;
        }

        $preparedDocument = new PreparedDocument(
            (string) $document[$this->engine->getConfiguration()->getPrimaryKey()],
            Util::encodeJson($documentData)
        );

        $singleAttributes = [];
        $multiAttributes = [];

        foreach ($this->engine->getIndexInfo()->getSingleFilterableAndSortableAttributes() as $attribute) {
            if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                continue;
            }

            $loupeType = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute);

            if ($loupeType === LoupeTypes::TYPE_GEO) {
                $singleAttributes[] = new SingleAttribute($attribute . '_geo_lat', isset($document[$attribute]['lat']) ? LoupeTypes::convertToFloat($document[$attribute]['lat']) : LoupeTypes::TYPE_NULL);
                $singleAttributes[] = new SingleAttribute($attribute . '_geo_lng', isset($document[$attribute]['lng']) ? LoupeTypes::convertToFloat($document[$attribute]['lng']) : LoupeTypes::TYPE_NULL);
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
                $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute)
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

        foreach ($tokensPerAttribute as $attributeName => $tokenCollection) {
            $termPosition = 1;
            foreach ($tokenCollection->all() as $token) {
                // Index the main term
                $terms[] = new Term($token->getTerm(), $attributeName, $termPosition, false);

                // Index variants
                foreach ($token->getVariants() as $termVariant) {
                    $terms[] = new Term($termVariant, $attributeName, $termPosition, false);
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
        $allDocumentIds = $preparedDocuments->allInternalIds();

        // Remove term relations of this document
        $this->engine->getConnection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE document IN (?)', IndexInfo::TABLE_NAME_TERMS_DOCUMENTS),
            [$allDocumentIds],
            [ArrayParameterType::INTEGER]
        );

        // Remove multi-attribute relations of this document
        $this->engine->getConnection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE document IN (?)', IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS),
            [$allDocumentIds],
            [ArrayParameterType::INTEGER]
        );

        // The rest (prefixes, state set, etc) is handled by reviseStorage()
    }

    private function removeOrphanedDocuments(): void
    {
        // Clean up term-document relations of documents which no longer exist
        $query = \sprintf(
            'DELETE FROM %s WHERE document NOT IN (SELECT _id FROM %s)',
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);

        // Clean up multi-attribute-document relations of documents which no longer exist
        $query = \sprintf(
            'DELETE FROM %s WHERE document NOT IN (SELECT _id FROM %s)',
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);
    }

    private function removeOrphanedPrefixes(): void
    {
        // Clean up prefix-term relations of terms which no longer exist
        $query = \sprintf(
            'DELETE FROM %s WHERE term NOT IN (SELECT id FROM %s)',
            IndexInfo::TABLE_NAME_PREFIXES_TERMS,
            IndexInfo::TABLE_NAME_TERMS,
        );

        $this->engine->getConnection()->executeStatement($query);

        // Clean up prefixes which no longer have any relations
        $this->removeOrphansFromTermsTable(
            IndexInfo::TABLE_NAME_PREFIXES,
            IndexInfo::TABLE_NAME_PREFIXES_TERMS,
            'prefix'
        );
    }

    private function removeOrphanedTerms(): void
    {
        // Clean up terms which no longer have any relations
        $this->removeOrphansFromTermsTable(
            IndexInfo::TABLE_NAME_TERMS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            'term'
        );
    }

    private function removeOrphans(): void
    {
        $this->removeOrphanedDocuments();
        $this->removeOrphanedTerms();
        $this->removeOrphanedPrefixes();
    }

    private function removeOrphansFromTermsTable(string $table, string $relationTable, string $column): void
    {
        // Iterate over all terms of documents which no longer exist
        // and remove them from the state set index
        $query = \sprintf(
            'SELECT %s FROM %s WHERE id NOT IN (SELECT %s FROM %s)',
            $column,
            $table,
            $column,
            $relationTable,
        );

        $iterator = $this->engine->getConnection()->executeQuery($query)->iterateAssociative();

        $stateSetIndex = $this->engine->getStateSetIndex();

        $chunkSize = 1000;
        $termsChunk = [];
        foreach ($iterator as $row) {
            $termsChunk[] = reset($row);

            if (\count($termsChunk) >= $chunkSize) {
                $stateSetIndex->removeFromIndex($termsChunk);
                $termsChunk = [];
            }
        }

        if (!empty($termsChunk)) {
            $stateSetIndex->removeFromIndex($termsChunk);
        }

        // Remove all orphaned terms from the terms table
        $query = \sprintf(
            'DELETE FROM %s WHERE id NOT IN (SELECT %s FROM %s)',
            $table,
            $column,
            $relationTable,
        );

        $this->engine->getConnection()->executeStatement($query);
    }

    private function reviseStorage(): void
    {
        $this->removeOrphans();
        $this->persistStateSet();
        $this->vacuumDatabase();
    }

    private function vacuumDatabase(): void
    {
        if (!$this->needsVacuum()) {
            return;
        }

        $this->engine->getConnection()->executeStatement('PRAGMA incremental_vacuum');
    }
}
