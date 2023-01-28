<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Internal\Index;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Exception\InvalidDocumentException;
use Terminal42\Loupe\Exception\PrimaryKeyNotFoundException;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\LoupeTypes;
use Terminal42\Loupe\Internal\Util;

class IndexInfo
{
    public const MAX_ATTRIBUTE_NAME_LENGTH = 30;

    public const TABLE_NAME_DOCUMENTS = 'documents';

    public const TABLE_NAME_INDEX_INFO = 'info';

    public const TABLE_NAME_MULTI_ATTRIBUTES = 'multi_attributes';

    public const TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS = 'multi_attributes_documents';

    public const TABLE_NAME_TERMS = 'terms';

    public const TABLE_NAME_TERMS_DOCUMENTS = 'terms_documents';

    private ?array $documentSchema = null;

    private ?bool $needsSetup = null;

    public function __construct(
        private Engine $engine
    ) {
    }

    public function setup(array $document)
    {
        $primaryKey = $this->engine->getConfiguration()
            ->getPrimaryKey();
        $sortableAttributes = $this->engine->getConfiguration()
            ->getSortableAttributes();

        if (! array_key_exists($primaryKey, $document)) {
            throw PrimaryKeyNotFoundException::becauseDoesNotExist($primaryKey);
        }

        $documentSchema = [];

        foreach ($document as $attributeName => $attributeValue) {
            self::validateAttributeName($attributeName);

            $loupeType = LoupeTypes::getTypeFromValue($attributeValue);

            if (in_array($attributeName, $sortableAttributes, true) && ! LoupeTypes::isSingleType($loupeType)) {
                throw InvalidDocumentException::becauseAttributeNotSortable($attributeName);
            }

            $documentSchema[$attributeName] = $loupeType;
        }

        $this->documentSchema = $documentSchema;
        $this->createSchema();

        $this->engine->getConnection()
            ->insert(self::TABLE_NAME_INDEX_INFO, [
                'key' => 'documentSchema',
                'value' => json_encode($documentSchema),
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

    public function getLoupeTypeForAttribute(string $attributeName): string
    {
        if (! array_key_exists($attributeName, $this->getDocumentSchema())) {
            throw new InvalidConfigurationException(sprintf(
                'The attribute "%s" does not exist on the document schema.',
                $attributeName
            ));
        }

        return $this->getDocumentSchema()[$attributeName];
    }

    public function getMultiFilterableAttributes(): array
    {
        $filterable = $this->engine->getConfiguration()
            ->getFilterableAttributes();
        $result = [];

        foreach ($filterable as $attributeName) {
            if (LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

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
            self::validateAttributeName($name);
            return true;
        } catch (InvalidDocumentException) {
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

    public static function validateAttributeName(string $name): void
    {
        if (strlen($name) > self::MAX_ATTRIBUTE_NAME_LENGTH
            || ! preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
        ) {
            throw InvalidDocumentException::becauseInvalidAttributeName($name);
        }
    }

    public function validateDocument(array $document): void
    {
        $documentSchema = $this->getDocumentSchema();

        if (count(array_diff_key($documentSchema, $document)) !== 0) {
            throw InvalidDocumentException::becauseDoesNotMatchSchema(
                $documentSchema,
                $document,
                $document[$this->engine->getConfiguration()->getPrimaryKey()] ?? null
            );
        }

        foreach ($document as $attributeName => $attributeValue) {
            if (! LoupeTypes::typeMatchesType(
                $documentSchema[$attributeName],
                LoupeTypes::getTypeFromValue($attributeValue)
            )) {
                throw InvalidDocumentException::becauseDoesNotMatchSchema(
                    $documentSchema,
                    $document,
                    $document[$this->engine->getConfiguration()->getPrimaryKey()] ?? null
                );
            }
        }
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

            if ($dbalType === null) {
                continue;
            }

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

    private function addTermsToDocumentsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS_DOCUMENTS);

        $table->addColumn('term', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('document', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('frequency', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['term', 'document']);
    }

    private function addTermsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('term', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('length', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('frequency', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term', 'length']);
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

        $this->addMultiAttributesToDocumentsRelationToSchema($schema);
        $this->addTermsToDocumentsRelationToSchema($schema);

        return $schema;
    }
}
