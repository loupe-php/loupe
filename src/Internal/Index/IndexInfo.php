<?php

namespace Terminal42\Loupe\Internal\Index;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Terminal42\Loupe\Exception\InvalidDocumentException;
use Terminal42\Loupe\Exception\PrimaryKeyNotFoundException;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\LoupeTypes;

class IndexInfo
{
    public const TABLE_NAME_DOCUMENTS = 'loupe_documents';
    public const TABLE_NAME_MULTI_ATTRIBUTES = 'loupe_multi_attributes';
    public const TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS = 'loupe_multi_attributes_documents';
    public const TABLE_NAME_TERMS_DOCUMENTS = 'loupe_terms_documents';
    public const TABLE_NAME_TERMS = 'loupe_terms';
    public const TABLE_NAME_INDEX_INFO = 'loupe_info';
    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    private ?bool $needsSetup = null;
    private ?array $documentSchema = null;

    public function __construct(private Engine $engine)
    {

    }

    public function getDocumentSchema(): array
    {
        if (null === $this->documentSchema) {
            $schema = $this->engine->getConnection()
                ->createQueryBuilder()
                ->select('value')
                ->from(self::TABLE_NAME_INDEX_INFO)
                ->where("key = 'documentSchema'")
                ->fetchOne();

            $this->documentSchema = json_decode($schema, true);
        }

        return $this->documentSchema;
    }

    public function getLoupeTypeForAttribute(string $attributeName): string
    {
        return $this->getDocumentSchema()[$attributeName];
    }

    public function needsSetup(): bool
    {
        if (null !== $this->needsSetup) {
            return $this->needsSetup;
        }

        return $this->needsSetup = !$this->engine->getConnection()
            ->createSchemaManager()
            ->tablesExist([self::TABLE_NAME_INDEX_INFO])
        ;
    }

    public function setup(array $document)
    {
        $primaryKey = $this->engine->getConfiguration()->getPrimaryKey();
        $sortableAttributes = $this->engine->getConfiguration()->getSortableAttributes();

        if (!array_key_exists($primaryKey, $document)) {
            throw PrimaryKeyNotFoundException::becauseDoesNotExist($primaryKey);
        }

        $documentSchema = [];

        foreach ($document as $attributeName => $attributeValue) {
            self::validateAttributeName($attributeName);

            $loupeType = LoupeTypes::getTypeFromValue($attributeValue);

            if (in_array($attributeName, $sortableAttributes, true) && !LoupeTypes::isSingleType($loupeType)) {
                throw InvalidDocumentException::becauseAttributeNotSortable($attributeName);
            }

            $documentSchema[$attributeName] = $loupeType;
        }

        $this->documentSchema = $documentSchema;
        $this->createSchema();

        $this->engine->getConnection()->insert(self::TABLE_NAME_INDEX_INFO, [
            'key' => 'documentSchema',
            'value' => json_encode($documentSchema)
        ]);

        $this->needsSetup = false;
    }

    public function validateDocument(array $document): void
    {
        $documentSchema = $this->getDocumentSchema();

        if (0 !== count(array_diff_key($documentSchema, $document))) {
            throw InvalidDocumentException::becauseDoesNotMatchSchema($documentSchema);
        }

        foreach ($document as $attributeName => $attributeValue) {
            if ($documentSchema[$attributeName] !== LoupeTypes::getTypeFromValue($attributeValue)) {
                throw InvalidDocumentException::becauseDoesNotMatchSchema($documentSchema);
            }
        }
    }



    private function getSchema(): Schema
    {
        $schema = new Schema();

        $this->addIndexInfoToSchema($schema);
        $this->addDocumentsToSchema($schema);
        $this->addMultiAttributesToSchema($schema);

        $this->addMultiAttributesToDocumentsRelationToSchema($schema);


        return $schema;

        $this->addTermsToSchema($schema);

        $this->addTermsToDocumentsRelationToSchema($schema);

        return $schema;
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

        foreach ($this->getSingleFilterableAndSortableAttributes() as $attribute) {
            $dbalType = match ($this->getLoupeTypeForAttribute($attribute)) {
                LoupeTypes::TYPE_STRING => Types::STRING,
                LoupeTypes::TYPE_NUMBER => Types::FLOAT,
                default => null
            };

            if (null === $dbalType) {
                continue;
            }

            $table->addColumn($attribute, $dbalType)
                ->setNotnull(false);

            $table->addIndex([$attribute]);
        }
    }

    public function getSingleFilterableAndSortableAttributes(): array
    {
        $filterableAndSortable = $this->engine->getConfiguration()->getFilterableAndSortableAttributes();
        $result = [];

        foreach ($filterableAndSortable as $attributeName) {
            if (!LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

    public function getMultiFilterableAttributes(): array
    {
        $filterable = $this->engine->getConfiguration()->getFilterableAttributes();
        $result = [];

        foreach ($filterable as $attributeName) {
            if (LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
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


    private function addTermsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('term', Types::STRING)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term']);
    }



    private function addMultiAttributesToDocumentsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS);

        $table->addColumn('attribute', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('document', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['attribute', 'document']);
    }

    private function addTermsToDocumentsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS_DOCUMENTS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('term', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('document', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term', 'document']);
    }

    private function createSchema(): void
    {
        $schemaManager = $this->engine->getConnection()->createSchemaManager();
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());

        $schemaManager->alterSchema($schemaDiff);
    }

    public static function validateAttributeName(string $name): void
    {
        if (strlen($name) > self::MAX_ATTRIBUTE_NAME_LENGTH
            || !preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
        ) {
            throw InvalidDocumentException::becauseInvalidAttributeName($name);
        }
    }

    public static function isValidAttributeName(string $name): bool
    {
        try {
            self::validateAttributeName($name);
            return true;
        } catch (InvalidDocumentException) {
            return false;
        }
    }
}