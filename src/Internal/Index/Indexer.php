<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Index;

use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\LoupeTypes;
use Terminal42\Loupe\Internal\Util;
use voku\helper\UTF8;

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
        return $this->engine->getTokenizer()
            ->tokenize($attributeValue);
    }

    private function indexAttributeValue(string $attribute, string|float $value, int $documentId)
    {
        $float = is_float($value);
        $valueColumn = $float ? 'numeric_value' : 'string_value';

        $data = [
            'attribute' => $attribute,
            $valueColumn => $value,
        ];

        $attributeId = Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
            $data,
            ['attribute', $valueColumn],
            'id'
        );

        Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS,
            [
                'attribute' => $attributeId,
                'document' => $documentId,
            ],
            ['attribute', 'document']
        );
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

        return Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_DOCUMENTS,
            $data,
            ['user_id'],
            'id'
        );
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
        $termId = Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_TERMS,
            [
                'term' => $term,
                'length' => UTF8::strlen($term),
                'frequency' => 1,
            ],
            ['term'],
            'id',
            [
                'frequency' => 'frequency + 1',
            ]
        );

        Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            [
                'term' => $termId,
                'document' => $documentId,
                'frequency' => 1,
            ],
            ['term', 'document'],
            '',
            [
                'frequency' => 'frequency + 1',
            ]
        );
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
