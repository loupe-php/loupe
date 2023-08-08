<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\Exception\PrimaryKeyNotFoundException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\LoupeTypes;
use Loupe\Loupe\Internal\Util;

class IndexInfo
{
    public const TABLE_NAME_ALPHABET = 'alphabet';

    public const TABLE_NAME_DOCUMENTS = 'documents';

    public const TABLE_NAME_INDEX_INFO = 'info';

    public const TABLE_NAME_MULTI_ATTRIBUTES = 'multi_attributes';

    public const TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS = 'multi_attributes_documents';

    public const TABLE_NAME_STATE_SET = 'state_set';

    public const TABLE_NAME_TERMS = 'terms';

    public const TABLE_NAME_TERMS_DOCUMENTS = 'terms_documents';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $documentSchema = null;

    private ?bool $needsSetup = null;

    public function __construct(
        private Engine $engine
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public function setup(array $document): void
    {
        $primaryKey = $this->engine->getConfiguration()->getPrimaryKey();
        $documentSchemaRelevantAttributes = $this->engine->getConfiguration()->getDocumentSchemaRelevantAttributes();
        $sortableAttributes = $this->engine->getConfiguration()->getSortableAttributes();

        if (! \array_key_exists($primaryKey, $document)) {
            throw PrimaryKeyNotFoundException::becauseDoesNotExist($primaryKey);
        }

        $documentSchema = [];

        foreach ($document as $attributeName => $attributeValue) {
            Configuration::validateAttributeName($attributeName);

            if (! \in_array($attributeName, $documentSchemaRelevantAttributes, true)) {
                continue;
            }

            $loupeType = LoupeTypes::getTypeFromValue($attributeValue);

            if (\in_array($attributeName, $sortableAttributes, true) && ! LoupeTypes::isSingleType($loupeType)) {
                throw InvalidConfigurationException::becauseAttributeNotSortable($attributeName);
            }

            $documentSchema[$attributeName] = $loupeType;
        }

        $this->documentSchema = $documentSchema;
        $this->createSchema();
        $this->updateDocumentSchema($documentSchema);

        $this->engine->getConnection()
            ->insert(self::TABLE_NAME_INDEX_INFO, [
                'key' => 'engineVersion',
                'value' => Engine::VERSION,
            ]);

        $this->engine->getConnection()
            ->insert(self::TABLE_NAME_INDEX_INFO, [
                'key' => 'configHash',
                'value' => $this->engine->getConfiguration()->getIndexHash(),
            ]);

        $this->needsSetup = false;
    }

    public function getAliasForTable(string $table): string
    {
        return match ($table) {
            self::TABLE_NAME_DOCUMENTS => 'd',
            self::TABLE_NAME_INDEX_INFO => 'i',
            self::TABLE_NAME_MULTI_ATTRIBUTES => 'ma',
            self::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS => 'mad',
            self::TABLE_NAME_TERMS => 't',
            self::TABLE_NAME_TERMS_DOCUMENTS => 'td',
            default => throw new \LogicException(sprintf('Forgot to define an alias for %s.', $table))
        };
    }

    public function getConfigHash(): string
    {
        return $this->engine->getConnection()
            ->createQueryBuilder()
            ->select('value')
            ->from(self::TABLE_NAME_INDEX_INFO)
            ->where("key = 'configHash'")
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentSchema(): array
    {
        if ($this->documentSchema === null) {
            $schema = $this->engine->getConnection()
                ->createQueryBuilder()
                ->select('value')
                ->from(self::TABLE_NAME_INDEX_INFO)
                ->where("key = 'documentSchema'")
                ->fetchOne();

            $this->documentSchema = Util::decodeJson($schema);
        }

        return $this->documentSchema;
    }

    public function getEngineVersion(): string
    {
        return $this->engine->getConnection()
            ->createQueryBuilder()
            ->select('value')
            ->from(self::TABLE_NAME_INDEX_INFO)
            ->where("key = 'engineVersion'")
            ->fetchOne();
    }

    public function getLoupeTypeForAttribute(string $attributeName): string
    {
        if (! \array_key_exists($attributeName, $this->getDocumentSchema())) {
            throw new InvalidConfigurationException(sprintf(
                'The attribute "%s" does not exist on the document schema.',
                $attributeName
            ));
        }

        return $this->getDocumentSchema()[$attributeName];
    }

    /**
     * @return array<string>
     */
    public function getMultiFilterableAttributes(): array
    {
        $result = [];

        foreach ($this->engine->getConfiguration()->getFilterableAttributes() as $attributeName) {
            if (LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public function getSingleFilterableAndSortableAttributes(): array
    {
        $filterableAndSortable = $this->engine->getConfiguration()
            ->getFilterableAndSortableAttributes();
        $result = [];

        foreach ($filterableAndSortable as $attributeName) {
            if (! LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

    public static function isValidAttributeName(string $name): bool
    {
        try {
            Configuration::validateAttributeName($name);
            return true;
        } catch (InvalidConfigurationException) {
            return false;
        }
    }

    public function needsSetup(): bool
    {
        if ($this->needsSetup !== null) {
            return $this->needsSetup;
        }

        return $this->needsSetup = ! $this->engine->getConnection()
            ->createSchemaManager()
            ->tablesExist([self::TABLE_NAME_INDEX_INFO])
        ;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function validateDocument(array $document): void
    {
        $documentSchema = $this->getDocumentSchema();

        if (\count(array_diff_key($documentSchema, $document)) !== 0) {
            throw InvalidDocumentException::becauseDoesNotMatchSchema(
                $documentSchema,
                $document,
                $document[$this->engine->getConfiguration()->getPrimaryKey()] ?? null
            );
        }

        $schemaNarrowed = false;

        foreach ($document as $attributeName => $attributeValue) {
            if (! isset($documentSchema[$attributeName])) {
                continue;
            }

            $valueType = LoupeTypes::getTypeFromValue($attributeValue);

            if (! LoupeTypes::typeMatchesType($documentSchema[$attributeName], $valueType)) {
                throw InvalidDocumentException::becauseDoesNotMatchSchema(
                    $documentSchema,
                    $document,
                    $document[$this->engine->getConfiguration()->getPrimaryKey()] ?? null
                );
            }

            // Update schema to narrower type (e.g. before it was "array" and now it becomes "array<string>" or before
            // it was "null" and now it becomes any other type.
            if ($documentSchema[$attributeName] !== $valueType) {
                $documentSchema[$attributeName] = $valueType;
                $schemaNarrowed = true;
            }
        }

        if ($schemaNarrowed) {
            $this->updateDocumentSchema($documentSchema);
        }
    }

    private function addAlphabetToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_ALPHABET);

        $table->addColumn('char', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('label', Types::INTEGER)
            ->setNotnull(true);

        $table->addUniqueIndex(['char', 'label']);
    }

    private function addDocumentsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_DOCUMENTS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('user_id', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('document', Types::TEXT)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id']);

        $columns = [];

        foreach ($this->getSingleFilterableAndSortableAttributes() as $attribute) {
            if ($attribute === $this->engine->getConfiguration()->getPrimaryKey()) {
                continue;
            }

            $loupeType = $this->getLoupeTypeForAttribute($attribute);

            if ($loupeType === LoupeTypes::TYPE_GEO) {
                $columns[$attribute . '_geo_lat'] = Types::FLOAT;
                $columns[$attribute . '_geo_lng'] = Types::FLOAT;
                continue;
            }

            $dbalType = match ($loupeType) {
                LoupeTypes::TYPE_STRING => Types::STRING,
                LoupeTypes::TYPE_NUMBER => Types::FLOAT,
                default => null
            };

            if ($dbalType === null) {
                continue;
            }

            $columns[$attribute] = $dbalType;
        }

        foreach ($columns as $attribute => $dbalType) {
            $table->addColumn($attribute, $dbalType)
                ->setNotnull(false);

            $table->addIndex([$attribute]);
        }
    }

    private function addIndexInfoToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_INDEX_INFO);

        $table->addColumn('key', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('value', Types::TEXT)
            ->setNotnull(true);

        $table->addUniqueIndex(['key']);
    }

    private function addMultiAttributesToDocumentsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS);

        $table->addColumn('attribute', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('document', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['attribute', 'document'], 'attribute_document');
    }

    private function addMultiAttributesToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_MULTI_ATTRIBUTES);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('attribute', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('string_value', Types::STRING)
            ->setNotnull(false);

        $table->addColumn('numeric_value', Types::FLOAT)
            ->setNotnull(false);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['attribute', 'string_value']);
        $table->addUniqueIndex(['attribute', 'numeric_value']);
    }

    private function addStateSetToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_STATE_SET);

        $table->addColumn('state', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('parent', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('mapped_char', Types::INTEGER)
            ->setNotnull(true);

        $table->addUniqueIndex(['state', 'parent', 'mapped_char']);
        $table->addIndex(['mapped_char']);
    }

    private function addTermsToDocumentsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS_DOCUMENTS);

        $table->addColumn('term', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('document', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('attribute', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('position', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['term', 'document', 'attribute', 'position']);
        $table->addIndex(['document']);
    }

    private function addTermsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('term', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('state', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('length', Types::INTEGER)
            ->setNotnull(true);

        // Inversed Document Frequency
        $table->addColumn('idf', Types::FLOAT)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term', 'state', 'length']);
    }

    private function createSchema(): void
    {
        $schemaManager = $this->engine->getConnection()
            ->createSchemaManager();
        $comparator = $schemaManager->createComparator();

        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());
        $schemaManager->alterSchema($schemaDiff);
    }

    private function getSchema(): Schema
    {
        $schema = new Schema();

        $this->addIndexInfoToSchema($schema);
        $this->addDocumentsToSchema($schema);
        $this->addMultiAttributesToSchema($schema);
        $this->addTermsToSchema($schema);
        $this->addAlphabetToSchema($schema);
        $this->addStateSetToSchema($schema);

        $this->addMultiAttributesToDocumentsRelationToSchema($schema);
        $this->addTermsToDocumentsRelationToSchema($schema);

        return $schema;
    }

    /**
     * @param array<string, mixed> $documentSchema
     */
    private function updateDocumentSchema(array $documentSchema): void
    {
        $this->engine->upsert(self::TABLE_NAME_INDEX_INFO, [
            'key' => 'documentSchema',
            'value' => json_encode($documentSchema),
        ], ['key']);

        $this->documentSchema = $documentSchema;
    }
}
