<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Index;

use Doctrine\DBAL\Connection;
use Terminal42\Loupe\Exception\IndexException;
use Terminal42\Loupe\Exception\LoupeExceptionInterface;
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

    /**
     * @throws LoupeExceptionInterface
     */
    public function addDocuments(array $documents): self
    {
        $firstDocument = reset($documents);

        $indexInfo = $this->engine->getIndexInfo();

        if ($indexInfo->needsSetup()) {
            $indexInfo->setup($firstDocument);
        }

        try {
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
        } catch (\Throwable $e) {
            if ($e instanceof LoupeExceptionInterface) {
                throw $e;
            }

            throw new IndexException($e->getMessage(), 0, $e);
        }

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

        $attributeId = $this->upsert(
            $this->engine->getConnection(),
            IndexInfo::TABLE_NAME_MULTI_ATTRIBUTES,
            $data,
            ['attribute', $valueColumn],
            'id'
        );

        $this->upsert(
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

        return $this->upsert(
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
        $termId = $this->upsert(
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

        $this->upsert(
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
            IndexInfo::TABLE_NAME_TERMS_DOCUMENTS,
        );

        $this->engine->getConnection()
            ->executeQuery($query);
    }

    /**
     * Unfortunately, we cannot use proper UPSERTs here (ON DUPLICATE() UPDATE) as somehow RETURNING does not work
     * properly with Doctrine. Maybe we can improve that one day.
     *
     * @return int The ID of the $insertIdColumn (either new when INSERT or existing when UPDATE)
     */
    private function upsert(
        Connection $connection,
        string $table,
        array $insertData,
        array $uniqueIndexColumns,
        string $insertIdColumn = ''
    ): ?int {
        if (count($insertData) === 0) {
            throw new \InvalidArgumentException('Need to provide data to insert.');
        }

        $qb = $connection->createQueryBuilder()
            ->select(array_filter(array_merge([$insertIdColumn], $uniqueIndexColumns)))
            ->from($table);

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $existing = $qb->executeQuery()
            ->fetchAssociative();

        if ($existing === false) {
            $connection->insert($table, $insertData);

            return (int) $connection->lastInsertId();
        }

        $qb = $connection->createQueryBuilder()
            ->update($table);

        foreach ($insertData as $columnName => $value) {
            $qb->set($columnName, $qb->createPositionalParameter($value));
        }

        foreach ($uniqueIndexColumns as $uniqueIndexColumn) {
            $qb->andWhere($uniqueIndexColumn . '=' . $qb->createPositionalParameter($insertData[$uniqueIndexColumn]));
        }

        $qb->executeQuery();

        return $insertIdColumn !== '' ? (int) $existing[$insertIdColumn] : null;
    }
}
