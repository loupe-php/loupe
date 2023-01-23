<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Index;

use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\LoupeTypes;
use Terminal42\Loupe\Internal\Util;

class Indexer
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function addDocument(array $document): self
    {
        $indexInfo = $this->engine->getIndexInfo();

        if ($indexInfo->needsSetup()) {
            $indexInfo->setup($document);
        } else {
            $indexInfo->validateDocument($document);
        }

        $this->engine->getConnection()
            ->transactional(function () use ($document) {
                $documentId = $this->indexDocument($document);
                $this->indexMultiAttributes($document, $documentId);
                $this->indexTerms($document, $documentId);
            });

        return $this;
    }

    private function extractTerms(string $attributeValue): array
    {
        // TODO: move into its own class

        return explode(' ', $attributeValue);
    }

    private function indexAttributeValue(string $attribute, string|float $value, int $documentId)
    {
        $float = is_float($value);

        $attributeId = $this->engine->getConnection()
            ->executeQuery(
                sprintf(
                    'SELECT id FROM %s WHERE attribute = :attribute AND %s = :value',
                    IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
                    $float ? 'numeric_value' : 'string_value'
                ),
                [
                    'attribute' => $attribute,
                    'value' => $value,
                ]
            )->fetchOne();

        if ($attributeId === false) {
            $this->engine->getConnection()
                ->insert(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES, [
                    'attribute' => $attribute,
                    $float ? 'numeric_value' : 'string_value' => $value,
                ]);
            $attributeId = $this->engine->getConnection()
                ->lastInsertId();
        }

        $this->engine->getConnection()
            ->insert(IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS, [
                'attribute' => $attributeId,
                'document' => $documentId,
            ]);
    }

    /**
     * @return int The document ID
     */
    private function indexDocument(array $document): int
    {
        $data = [
            'user_id' => (string) $document[$this->engine->getConfiguration()->getPrimaryKey()],
            'document' => Util::encodeJson($document),
        ];

        foreach ($this->engine->getIndexInfo()->getSingleFilterableAndSortableAttributes() as $attribute) {
            $data[$attribute] = LoupeTypes::convertValueToType(
                $document[$attribute],
                $this->engine->getIndexInfo()
                    ->getLoupeTypeForAttribute($attribute)
            );
        }

        $this->engine->getConnection()
            ->insert(IndexInfo::TABLE_NAME_DOCUMENTS, $data);

        return (int) $this->engine->getConnection()
            ->lastInsertId();
    }

    private function indexMultiAttributes(array $document, int $documentId): void
    {
        foreach ($this->engine->getIndexInfo()->getMultiFilterableAttributes() as $attribute) {
            $attributeValue = $document[$attribute];

            $convertedValue = LoupeTypes::convertValueToType(
                $attributeValue,
                $this->engine->getIndexInfo()
                    ->getLoupeTypeForAttribute($attribute)
            );

            if (is_array($convertedValue)) {
                foreach ($convertedValue as $value) {
                    $this->indexAttributeValue($attribute, $value, $documentId);
                }
            } else {
                $this->indexAttributeValue($attribute, $convertedValue, $documentId);
            }
        }
    }

    private function indexTerm(string $term, int $documentId): void
    {
        $termId = $this->engine->getConnection()
            ->executeQuery(sprintf('SELECT id FROM %s WHERE term = :term', IndexInfo::TABLE_NAME_TERMS), [
                'term' => $term,
            ])->fetchOne();

        if ($termId === false) {
            $this->engine->getConnection()
                ->insert(IndexInfo::TABLE_NAME_TERMS, [
                    'term' => $term,
                    'frequency' => 1,
                ]);
            $termId = $this->engine->getConnection()
                ->lastInsertId();
        } else {
            $this->engine->getConnection()
                ->update(IndexInfo::TABLE_NAME_TERMS, [
                    'frequency' => 'frequency + 1',
                ], [
                    'id' => $termId,
                ]);
        }

        $relation = $this->engine->getConnection()
            ->executeQuery(
                sprintf(
                    'SELECT term,document FROM %s WHERE term = :term AND document = :document',
                    IndexInfo::TABLE_NAME_TERMS_DOCUMENTS
                ),
                [
                'term' => $term,
                'document' => $documentId,
            ]
            )->fetchOne();

        if ($relation === false) {
            $this->engine->getConnection()
                ->insert(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, [
                    'term' => $term,
                    'document' => $documentId,
                    'frequency' => 1,
                ]);

            return;
        }

        $this->engine->getConnection()
            ->update(IndexInfo::TABLE_NAME_TERMS_DOCUMENTS, [
                'frequency' => 'frequency + 1',
            ], [
                'term' => $term,
                'document' => $documentId,
            ]);
    }

    private function indexTerms(array $document, int $documentId): void
    {
        $searchableAttributes = $this->engine->getConfiguration()
            ->getValue('searchableAttributes');

        foreach ($document as $attributeName => $attributeValue) {
            if (['*'] !== $searchableAttributes && ! in_array($attributeName, $searchableAttributes, true)) {
                continue;
            }

            $attributeValue = LoupeTypes::convertToString($attributeValue);

            foreach ($this->extractTerms($attributeValue) as $term) {
                $this->indexTerm($term, $documentId);
            }
        }
    }
}
