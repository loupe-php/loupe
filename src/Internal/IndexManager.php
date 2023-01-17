<?php

namespace Terminal42\Loupe\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\API\SQLite\UserDefinedFunctions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Exception\InvalidJsonException;
use Terminal42\Loupe\Exception\PrimaryKeyNotFoundException;
use Terminal42\Loupe\Index\Index;
use Terminal42\Loupe\Internal\Search\ResultFetcher;

class IndexManager
{
    public const TABLE_NAME_DOCUMENTS = 'loupe_documents';
    public const TABLE_NAME_ATTRIBUTES = 'loupe_attributes';
    public const TABLE_NAME_TERMS = 'loupe_terms';

    public function __construct(private Connection $connection, private array $configuration)
    {
        if (!$this->connection->getDriver() instanceof AbstractSQLiteDriver) {
            throw new \InvalidArgumentException('Only SQLite is supported.');
        }

        $this->registerSQLiteFunctions();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getConfigurationValueForIndex(string $index, string $configKey): mixed
    {
        if (!array_key_exists($index, $this->configuration['indexes'])) {
            throw InvalidConfigurationException::becauseIndexConfigurationMissing($index);
        }

        return $this->configuration['indexes'][$index][$configKey] ?? null;
    }

    public function addDocument(array $document, string $index): self
    {
        if (!array_key_exists($this->getConfigurationValueForIndex($index, 'primaryKey'), $document)) {
            throw PrimaryKeyNotFoundException::becauseDoesNotExist($this->getConfigurationValueForIndex($index, 'primaryKey'));
        }

        foreach (array_keys($document) as $attributeName) {
            Util::validateAttributeName($attributeName);
        }

        $this->connection->transactional(function () use ($document, $index) {
            // Document
            $this->connection->insert(self::TABLE_NAME_DOCUMENTS, [
                'index_name' => $index,
                'user_id' => (string) $document[$this->getConfigurationValueForIndex($index, 'primaryKey')],
                'document' => $this->encodeJson($document)
            ]);

            $documentId = $this->connection->lastInsertId();

            // Attributes
            $this->indexAttributes($document, $index, $documentId);
        });

        return $this;
    }

    private function indexAttributes( array $document, string $index, int $documentId): void
    {
        foreach ($this->getFilterableAndSortableAttributes($index) as $attribute) {
            if (!array_key_exists($attribute, $document)) {
                continue;
            }

            $attributeValue = $document[$attribute];
            $valuesToIndex = [];

            if (is_array($attributeValue)) {
                foreach ($attributeValue as $v) {
                    $valuesToIndex[] = Util::convertToStringOrFloat($v);
                }
            } else {
                $valuesToIndex[] = Util::convertToStringOrFloat($attributeValue);
            }

            foreach ($valuesToIndex as $value) {
                $data = [
                    'document' => $documentId,
                    'attribute' => $attribute,
                ];

                $data[is_float($value) ? 'numeric_value' : 'string_value'] = $value;

                $this->connection->insert(self::TABLE_NAME_ATTRIBUTES, $data);
            }
        }
    }

    public function getFilterableAndSortableAttributes(string $index): array
    {
        return array_unique(array_merge(
            $this->getConfigurationValueForIndex($index, 'filterableAttributes'),
            $this->getConfigurationValueForIndex($index, 'sortableAttributes')
        ));
    }


    public function getIndex(string $name): Index
    {
        return new Index($this, $name);
    }


    private function registerSQLiteFunctions()
    {
        UserDefinedFunctions::register(
            [$this->connection->getNativeConnection(), 'sqliteCreateFunction'],
            [
                'levenshtein'  => ['callback' => [Levenshtein::class, 'levenshtein'], 'numArgs' => 2],
                'max_levenshtein' => ['callback' => [Levenshtein::class, 'maxLevenshtein'], 'numArgs' => 3],
            ]
        );
    }

    public function getSchema(): Schema
    {
        $schema = new Schema();

        $this->addDocumentsToSchema($schema);
        $this->addAttributesToSchema($schema);
        $this->addTermsToSchema($schema);


        return $schema;
    }

    private function addDocumentsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_DOCUMENTS);

        $table->addColumn('id', Types::BIGINT)
            ->setAutoincrement(true)
            ->setNotnull(true);

        $table->addColumn('index_name', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('user_id', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('document', Types::TEXT)
            ->setNotnull(true);

        $table->addUniqueIndex(['id']);
        $table->addIndex(['index_name', 'user_id']);
    }

    private function addAttributesToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_ATTRIBUTES);

        $table->addColumn('id', Types::BIGINT)
            ->setAutoincrement(true)
            ->setNotnull(true);

        $table->addColumn('document', Types::BIGINT)
            ->setNotnull(true);

        $table->addColumn('attribute', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('string_value', Types::STRING)
            ->setNotnull(false);

        $table->addColumn('numeric_value', Types::FLOAT)
            ->setNotnull(false);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['document']);
        $table->addIndex(['attribute']);
        $table->addIndex(['string_value']);
        $table->addIndex(['numeric_value']);
    }


    private function addTermsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS);

        $table->addColumn('id', Types::BIGINT)
            ->setAutoincrement(true)
            ->setNotnull(true);

        $table->addColumn('term', Types::STRING)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term']);
    }

    public function createSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());

        $schemaManager->alterSchema($schemaDiff);
    }

    private function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);

        if (false === $json) {
            throw new InvalidJsonException(json_last_error());
        }

        return $json;
    }

    public function search(array $parameters, string $index): array
    {
        $resultFetcher = new ResultFetcher($this, $parameters, $index);

        return $resultFetcher->fetchResult();
    }
}