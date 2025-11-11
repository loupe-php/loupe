<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Doctrine\DBAL\ArrayParameterType;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Index\PreparedDocument\MultiAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\SingleAttribute;
use Loupe\Loupe\Internal\Index\PreparedDocument\Term;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\TicketHandler;

class Indexer
{
    /**
     * @var array<int, callable>
     */
    private array $changes = [];

    /**
     * @var array<string, int>
     */
    private array $prefixCache = [];

    /**
     * @var array<string,bool>
     */
    private array $prefixTermCache = [];

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

        // Fix, validate and record schema updates if needed
        foreach ($documents as $document) {
            $this->engine->getIndexInfo()->fixAndValidateDocument($document);
        }

        // Now prepare the documents
        $preparedDocuments = [];
        foreach ($documents as $document) {
            $preparedDocuments[] = $this->prepareDocument($document);
        }

        $this->recordChange(function () use ($preparedDocuments) {
            $this->writePreparedDocuments($preparedDocuments);

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
                    \sprintf('DELETE FROM %s WHERE user_id IN(:ids)', IndexInfo::TABLE_NAME_DOCUMENTS),
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

    private function indexAttributeValue(string $attribute, string|float|bool|null $value, int $documentId): void
    {
        if ($value === null) {
            return;
        }

        $valueColumn = (\is_float($value) || \is_bool($value)) ? 'numeric_value' : 'string_value';

        $data = [
            'attribute' => $attribute,
            $valueColumn => $value,
        ];

        $attributeId = $this->engine->upsert(
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
            $data,
            ['attribute', $valueColumn],
            'id'
        );

        $this->engine->upsert(
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            [
                'attribute' => $attributeId,
                'document' => $documentId,
            ],
            ['attribute', 'document']
        );
    }

    private function indexPrefix(string $prefix, int $termId): void
    {
        $cacheKey = $prefix . ';' . $termId;

        if (isset($this->prefixTermCache[$cacheKey])) {
            return;
        }

        if (!isset($this->prefixCache[$prefix])) {
            if ($this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
                $state = 0;
            } else {
                $state = $this->engine->getStateSetIndex()->index([$prefix])[$prefix];
            }

            $this->prefixCache[$prefix] = (int) $this->engine->upsert(
                IndexInfo::TABLE_NAME_PREFIXES,
                [
                    'prefix' => $prefix,
                    'length' => mb_strlen($prefix, 'UTF-8'),
                    'state' => $state,
                ],
                ['prefix', 'length'],
                'id'
            );
        }

        $this->engine->upsert(
            IndexInfo::TABLE_NAME_PREFIXES_TERMS,
            [
                'prefix' => $this->prefixCache[$prefix],
                'term' => $termId,
            ],
            ['prefix', 'term'],
            ''
        );

        $this->prefixTermCache[$cacheKey] = true;
    }

    private function indexPrefixes(string $term, int $termId): void
    {
        // Prefix typo search disabled = we don't need to index them
        if (!$this->engine->getConfiguration()->getTypoTolerance()->isEnabledForPrefixSearch()) {
            return;
        }

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

            $this->indexPrefix(implode('', $prefix), $termId);
        }
    }

    private function indexTerm(string $term, int $documentId, string $attributeName, int $termPosition): int
    {
        if ($this->engine->getConfiguration()->getTypoTolerance()->isDisabled()) {
            $state = 0;
        } else {
            $state = $this->engine->getStateSetIndex()->index([$term])[$term];
        }

        $termId = (int) $this->engine->upsert(
            IndexInfo::TABLE_NAME_TERMS,
            [
                'term' => $term,
                'state' => $state,
                'length' => mb_strlen($term, 'UTF-8'),
            ],
            ['term', 'state', 'length'],
            'id'
        );

        $this->engine->upsert(
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            [
                'term' => $termId,
                'document' => $documentId,
                'attribute' => $attributeName,
                'position' => $termPosition,
            ],
            ['term', 'document', 'attribute', 'position'],
            ''
        );

        return $termId;
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
            $documentData
        );

        $singleAttributes = [];
        $multiAttributes = [];

        foreach ($this->engine->getIndexInfo()->getSingleFilterableAndSortableAttributes() as $attribute) {
            if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                continue;
            }

            $loupeType = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute);

            if ($loupeType === LoupeTypes::TYPE_GEO) {
                if (!isset($document[$attribute]['lat'], $document[$attribute]['lng'])) {
                    continue;
                }

                $singleAttributes[] = new SingleAttribute($attribute . '_geo_lat', $document[$attribute]['lat']);
                $singleAttributes[] = new SingleAttribute($attribute . '_geo_lng', $document[$attribute]['lng']);
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

    private function removeDocumentData(int $documentId): void
    {
        // Remove term relations of this document
        $query = \sprintf(
            'DELETE FROM %s WHERE document = %d',
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            $documentId
        );

        $this->engine->getConnection()->executeStatement($query);

        // Remove multi-attribute relations of this document
        $query = \sprintf(
            'DELETE FROM %s WHERE document = %d',
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            $documentId
        );

        $this->engine->getConnection()->executeStatement($query);

        // The rest (prefixes, state set, etc) is handled by reviseStorage()
    }

    private function removeOrphanedDocuments(): void
    {
        // Clean up term-document relations of documents which no longer exist
        $query = \sprintf(
            'DELETE FROM %s WHERE document NOT IN (SELECT id FROM %s)',
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);

        // Clean up multi-attribute-document relations of documents which no longer exist
        $query = \sprintf(
            'DELETE FROM %s WHERE document NOT IN (SELECT id FROM %s)',
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

    /**
     * @param array<PreparedDocument> $documents
     */
    private function writePreparedDocuments(array $documents): void
    {
        // TODO: optimize this entire logic for bulk inserts
        foreach ($documents as $document) {
            $data = [
                'user_id' => $document->getUserId(),
                'document' => $document->getJsonEncodedDocumentData(),
            ];

            foreach ($document->getSingleAttributes() as $attribute) {
                $data[$attribute->getName()] = $attribute->getValue();
            }

            $documentId = (int) $this->engine->upsert(
                IndexInfo::TABLE_NAME_DOCUMENTS,
                $data,
                ['user_id'],
                'id'
            );

            // TODO: This can be optimized for sure
            $this->removeDocumentData($documentId);

            // TODO: optimize me
            foreach ($document->getMultiAttributes() as $attribute) {
                foreach ($attribute->getValues() as $value) {
                    $this->indexAttributeValue($attribute->getName(), $value, $documentId);
                }
            }

            // TODO: optimize me
            foreach ($document->getTerms() as $term) {
                // Main terms
                if (!$term->isVariant()) {
                    $termId = $this->indexTerm($term->getTerm(), $documentId, $term->getAttribute(), $term->getPosition());

                    // Index prefixes for the main term (not for variants though)
                    $this->indexPrefixes($term->getTerm(), $termId);

                    continue;
                }

                // Variants
                $this->indexTerm($term->getTerm(), $documentId, $term->getAttribute(), $term->getPosition());
            }
        }
    }
}
