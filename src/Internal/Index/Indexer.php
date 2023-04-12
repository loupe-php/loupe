<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Index;

use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\LoupeTypes;
use Terminal42\Loupe\Internal\Tokenizer\TokenCollection;
use Terminal42\Loupe\Internal\Util;
use voku\helper\UTF8;

class Indexer
{
    public function __construct(
        private Engine $engine
    ) {
    }

    public function addDocuments(array $documents): self
    {
        $firstDocument = reset($documents);

        $indexInfo = $this->engine->getIndexInfo();

        if ($indexInfo->needsSetup()) {
            $indexInfo->setup($firstDocument);
        }

        $this->engine->getConnection()
            ->transactional(function () use ($indexInfo, $documents) {
                foreach ($documents as $document) {
                    $indexInfo->validateDocument($document);

                    $this->engine->getConnection()
                        ->transactional(function () use ($document) {
                            $documentId = $this->indexDocument($document);
                            $this->indexMultiAttributes($document, $documentId);
                            $this->indexTerms($document, $documentId);
                        });
                }

                // Update IDF only once
                $this->updateInverseDocumentFrequencies();
            });

        return $this;
    }

    private function extractTokens(string $attributeValue): TokenCollection
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
            $loupeType = $this->engine->getIndexInfo()
                ->getLoupeTypeForAttribute($attribute);

            if ($loupeType === LoupeTypes::TYPE_GEO) {
                $data['_geo_lat'] = $document[$attribute]['lat'];
                $data['_geo_lng'] = $document[$attribute]['lng'];
                continue;
            }

            $data[$attribute] = LoupeTypes::convertValueToType($document[$attribute], $loupeType);
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

    private function indexTerm(string $term, int $documentId, float $normalizedTermFrequency): void
    {
        $termId = Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_TERMS,
            [
                'term' => $term,
                'length' => UTF8::strlen($term),
                'idf' => 1,
            ],
            ['term'],
            'id'
        );

        Util::upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            [
                'term' => $termId,
                'document' => $documentId,
                'ntf' => $normalizedTermFrequency,
            ],
            ['term', 'document'],
            ''
        );
    }

    private function indexTerms(array $document, int $documentId): void
    {
        $searchableAttributes = $this->engine->getConfiguration()
            ->getValue('searchableAttributes');

        $termsAndFrequency = [];
        $totalTermsInDocument = 0;

        foreach ($document as $attributeName => $attributeValue) {
            if (['*'] !== $searchableAttributes && ! in_array($attributeName, $searchableAttributes, true)) {
                continue;
            }

            $attributeValue = LoupeTypes::convertToString($attributeValue);

            foreach ($this->extractTokens($attributeValue)->allTokensWithVariants() as $term) {
                // Prefix with a nonsense character to ensure PHP also treats numerics like strings in this array.
                $term = 't' . $term;

                if (! isset($termsAndFrequency[$term])) {
                    $termsAndFrequency[$term] = 1;
                } else {
                    $termsAndFrequency[$term]++;
                }

                $totalTermsInDocument++;
            }
        }

        if ($totalTermsInDocument === 0) {
            return;
        }

        foreach ($termsAndFrequency as $term => $frequency) {
            // Remove the prefix again
            $this->indexTerm(substr($term, 1), $documentId, $frequency / $totalTermsInDocument);
        }
    }

    private function updateInverseDocumentFrequencies(): void
    {
        // TODO: Cleanup all terms that are not in terms_documents anymore (to prevent division by 0)

        $query = <<<'QUERY'
            UPDATE 
              %s 
            SET 
              idf = 1.0 + LN(
                (SELECT COUNT(*) FROM %s)
                    /
                (SELECT COUNT(*) FROM %s AS td WHERE td.term = id )
              )
QUERY;

        $query = sprintf(
            $query,
            IndexInfo::TABLE_NAME_TERMS,
            IndexInfo::TABLE_NAME_DOCUMENTS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        );

        $this->engine->getConnection()
            ->executeQuery($query);
    }
}
