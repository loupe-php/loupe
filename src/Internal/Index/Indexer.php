<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Doctrine\DBAL\ArrayParameterType;
use Loupe\Loupe\Exception\IndexException;
use Loupe\Loupe\Exception\LoupeExceptionInterface;
use Loupe\Loupe\IndexResult;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\StateSetIndex\StateSet;
use Loupe\Loupe\Internal\Util;
use voku\helper\UTF8;

class Indexer
{
    /**
     * @var array<string, int>
     */
    private array $prefixCache = [];

    /**
     * @var array<string,bool>
     */
    private array $prefixTermCache = [];

    public function __construct(
        private Engine $engine
    ) {
    }

    /**
     * @param array<array<string, mixed>> $documents
     * @throws IndexException In case something really went wrong - this shouldn't happen and should be reported as a bug
     */
    public function addDocuments(array $documents): IndexResult
    {
        if ($documents === []) {
            return new IndexResult(0);
        }

        $firstDocument = reset($documents);

        $indexInfo = $this->engine->getIndexInfo();

        if ($indexInfo->needsSetup()) {
            $indexInfo->setup($firstDocument);
        }

        $documentExceptions = [];
        $successfulCount = 0;

        try {
            $this->engine->getConnection()
                ->transactional(function () use ($indexInfo, $documents, &$successfulCount, &$documentExceptions) {
                    foreach ($documents as $document) {
                        try {
                            $indexInfo->fixAndValidateDocument($document);

                            $this->engine->getConnection()
                                ->transactional(function () use ($document) {
                                    $documentId = $this->indexDocument($document);
                                    $this->indexMultiAttributes($document, $documentId);
                                    $this->indexTerms($document, $documentId);
                                });

                            $successfulCount++;
                        } catch (LoupeExceptionInterface $exception) {
                            $primaryKey = $document[$this->engine->getConfiguration()->getPrimaryKey()] ?? null;

                            // We cannot report this exception on the document because the key is missing - we have to
                            // abort early here.
                            if ($primaryKey === null) {
                                return new IndexResult($successfulCount, $documentExceptions, $exception);
                            }

                            $documentExceptions[$primaryKey] = $exception;
                        }
                    }

                    $this->persistStateSet();

                    // Update storage (IDF etc.) only once
                    $this->reviseStorage();
                });
        } catch (\Throwable $e) {
            if ($e instanceof LoupeExceptionInterface) {
                return new IndexResult($successfulCount, $documentExceptions, $e);
            }

            throw new IndexException($e->getMessage(), 0, $e);
        }

        return new IndexResult($successfulCount, $documentExceptions);
    }

    /**
     * @param array<int|string> $ids
     */
    public function deleteDocuments(array $ids): self
    {
        if ($this->engine->getIndexInfo()->needsSetup()) {
            return $this;
        }

        $this->engine->getConnection()
            ->executeStatement(
                sprintf('DELETE FROM %s WHERE user_id IN(:ids)', IndexInfo::TABLE_NAME_DOCUMENTS),
                [
                    'ids' => LoupeTypes::convertToArrayOfStrings($ids),
                ],
                [
                    'ids' => ArrayParameterType::STRING,
                ]
            );

        $this->reviseStorage();

        return $this;
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

    /**
     * @param array<string, mixed> $document
     * @return int The document ID
     */
    private function indexDocument(array $document): int
    {
        $data = [
            'user_id' => (string) $document[$this->engine->getConfiguration()->getPrimaryKey()],
            'document' => Util::encodeJson($document),
        ];

        foreach ($this->engine->getIndexInfo()->getSingleFilterableAndSortableAttributes() as $attribute) {
            if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                continue;
            }

            $loupeType = $this->engine->getIndexInfo()
                ->getLoupeTypeForAttribute($attribute);

            if ($loupeType === LoupeTypes::TYPE_GEO) {
                if (!isset($document[$attribute]['lat'], $document[$attribute]['lng'])) {
                    continue;
                }

                $data[$attribute . '_geo_lat'] = $document[$attribute]['lat'];
                $data[$attribute . '_geo_lng'] = $document[$attribute]['lng'];
                continue;
            }

            $data[$attribute] = LoupeTypes::convertValueToType($document[$attribute], $loupeType);
        }

        // Markers for IS EMPTY and IS NULL filters on multi attributes
        foreach ($this->engine->getIndexInfo()->getMultiFilterableAttributes() as $attribute) {
            $loupeType = $this->engine->getIndexInfo()->getLoupeTypeForAttribute($attribute);
            $value = LoupeTypes::convertValueToType($document[$attribute], $loupeType);

            if (\is_array($value)) {
                $data[$attribute] = \count($value);
            } else {
                $data[$attribute] = $value;
            }
        }

        return (int) $this->engine->upsert(
            IndexInfo::TABLE_NAME_DOCUMENTS,
            $data,
            ['user_id'],
            'id'
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private function indexMultiAttributes(array $document, int $documentId): void
    {
        foreach ($this->engine->getIndexInfo()->getMultiFilterableAttributes() as $attribute) {
            $attributeValue = $document[$attribute];

            $convertedValue = LoupeTypes::convertValueToType(
                $attributeValue,
                $this->engine->getIndexInfo()
                    ->getLoupeTypeForAttribute($attribute)
            );

            if (\is_array($convertedValue)) {
                foreach ($convertedValue as $value) {
                    $this->indexAttributeValue($attribute, $value, $documentId);
                }
            } else {
                $this->indexAttributeValue($attribute, $convertedValue, $documentId);
            }
        }
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
                    'length' => UTF8::strlen($prefix),
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
                'length' => UTF8::strlen($term),
                'idf' => 1,
            ],
            ['term'],
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

    /**
     * @param array<string, mixed> $document
     */
    private function indexTerms(array $document, int $documentId): void
    {
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
                $termId = $this->indexTerm($token->getTerm(), $documentId, $attributeName, $termPosition);

                // Index prefixes for the main term (not for variants though)
                $this->indexPrefixes($token->getTerm(), $termId);

                // Index variants
                foreach ($token->getVariants() as $termVariant) {
                    $this->indexTerm($termVariant, $documentId, $attributeName, $termPosition);
                }

                ++$termPosition;
            }
        }
    }

    private function persistStateSet(): void
    {
        /** @var StateSet $stateSet */
        $stateSet = $this->engine->getStateSetIndex()->getStateSet();
        $stateSet->persist();
    }

    private function removeOrphans(): void
    {
        // Cleanup all terms of documents which no longer exist
        $query = <<<'QUERY'
            DELETE FROM %s WHERE document NOT IN (SELECT id FROM %s)
           QUERY;

        $query = sprintf(
            $query,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);

        // Cleanup all multi attributes of documents which no longer exist
        $query = <<<'QUERY'
            DELETE FROM %s WHERE document NOT IN (SELECT id FROM %s)
           QUERY;

        $query = sprintf(
            $query,
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);

        // Cleanup all terms that are not in terms_documents anymore
        $query = <<<'QUERY'
            DELETE FROM %s WHERE id NOT IN (SELECT term FROM %s)
           QUERY;

        $query = sprintf(
            $query,
            IndexInfo::TABLE_NAME_TERMS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);
    }

    private function reviseStorage(): void
    {
        $this->removeOrphans();
        $this->updateInverseDocumentFrequencies();
    }

    private function updateInverseDocumentFrequencies(): void
    {
        // Notice the * 1.0 additions to the COUNT() SELECTS in order to force floating point calculations
        $query = <<<'QUERY'
            UPDATE 
              %s 
            SET 
              idf = 1.0 + (LN(
                (SELECT COUNT(*) FROM %s) * 1.0
                    /
                (SELECT COUNT(*) FROM %s AS td WHERE td.term = id ) * 1.0
              ))
QUERY;

        $query = sprintf(
            $query,
            IndexInfo::TABLE_NAME_TERMS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        );

        $this->engine->getConnection()->executeStatement($query);
    }
}
