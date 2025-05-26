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
    public const TABLE_NAME_DOCUMENTS = 'documents';

    public const TABLE_NAME_INDEX_INFO = 'info';

    public const TABLE_NAME_MULTI_ATTRIBUTES = 'multi_attributes';

    public const TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS = 'multi_attributes_documents';

    public const TABLE_NAME_PREFIXES = 'prefixes';

    public const TABLE_NAME_PREFIXES_TERMS = 'prefixes_terms';

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

        if (!\array_key_exists($primaryKey, $document)) {
            throw PrimaryKeyNotFoundException::becauseDoesNotExist($primaryKey);
        }

        $documentSchema = [];

        foreach ($document as $attributeName => $attributeValue) {
            Configuration::validateAttributeName($attributeName);

            if (!\in_array($attributeName, $documentSchemaRelevantAttributes, true)) {
                continue;
            }

            $documentSchema[$attributeName] = LoupeTypes::getTypeFromValue($attributeValue);
        }

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

    /**
     * @param array<string, mixed> $document
     */
    public function fixAndValidateDocument(array &$document): void
    {
        $documentSchema = $this->getDocumentSchema();
        $documentSchemaRelevantAttributes = $this->engine->getConfiguration()->getDocumentSchemaRelevantAttributes();
        $primaryKey = $document[$this->engine->getConfiguration()->getPrimaryKey()] ?
            (string) $document[$this->engine->getConfiguration()->getPrimaryKey()] :
            null;

        $missingAttributes = array_keys(array_diff_key($documentSchema, $document));

        if ($missingAttributes !== []) {
            foreach ($missingAttributes as $missingAttribute) {
                $document[$missingAttribute] = null;
            }
        }

        $needsSchemaUpdate = false;

        foreach ($document as $attributeName => $attributeValue) {
            $valueType = LoupeTypes::getTypeFromValue($attributeValue);

            // If the attribute does not exist on the attribute yet, we need to add it to the schema in case it is
            // configured as being schema relevant. Otherwise, we just ignore and skip.
            if (!isset($documentSchema[$attributeName])) {
                if (\in_array($attributeName, $documentSchemaRelevantAttributes, true)) {
                    $documentSchema[$attributeName] = $valueType;
                    $needsSchemaUpdate = true;
                }

                continue;
            }

            if (!LoupeTypes::typeMatchesType($documentSchema[$attributeName], $valueType)) {
                throw InvalidDocumentException::becauseDoesNotMatchSchema(
                    $documentSchema,
                    $document,
                    $primaryKey
                );
            }

            // Update schema to narrower type (e.g. before it was "array" and now it becomes "array<string>" or before
            // it was "null" and now it becomes any other type.
            if (LoupeTypes::typeIsNarrowerThanType($documentSchema[$attributeName], $valueType)) {
                $documentSchema[$attributeName] = $valueType;
                $needsSchemaUpdate = true;
            }
        }

        if ($needsSchemaUpdate) {
            $this->updateDocumentSchema($documentSchema);
        }
    }

    public function getAliasForTable(string $table, string $suffix = ''): string
    {
        return match ($table) {
            self::TABLE_NAME_DOCUMENTS => 'd',
            self::TABLE_NAME_INDEX_INFO => 'i',
            self::TABLE_NAME_MULTI_ATTRIBUTES => 'ma',
            self::TABLE_NAME_MULTI_ATTRIBUTES_DOCUMENTS => 'mad',
            self::TABLE_NAME_TERMS => 't',
            self::TABLE_NAME_TERMS_DOCUMENTS => 'td',
            self::TABLE_NAME_PREFIXES => 'p',
            self::TABLE_NAME_PREFIXES_TERMS => 'tp',
            default => throw new \LogicException(sprintf('Forgot to define an alias for %s.', $table))
        } . $suffix;
    }

    public function getConfigHash(): string
    {
        return (string) $this->engine->getConnection()
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

            if ($schema === false) {
                $this->documentSchema = [];
            } else {
                $this->documentSchema = Util::decodeJson($schema);
            }
        }

        return $this->documentSchema;
    }

    public function getEngineVersion(): string
    {
        $version = $this->engine->getConnection()
            ->createQueryBuilder()
            ->select('value')
            ->from(self::TABLE_NAME_INDEX_INFO)
            ->where("key = 'engineVersion'")
            ->fetchOne();

        if ($version === false) {
            return Engine::VERSION;
        }

        return (string) $version;
    }

    /**
     * @return array<string>
     */
    public function getFilterableAndSortableAttributes(): array
    {
        return array_unique(array_merge($this->getFilterableAttributes(), $this->getSortableAttributes()));
    }

    /**
     * @return array<string>
     */
    public function getFilterableAttributes(): array
    {
        return array_flip(array_intersect_key(array_flip($this->engine->getConfiguration()->getFilterableAttributes()), $this->getDocumentSchema()));
    }

    public function getLoupeTypeForAttribute(string $attributeName): string
    {
        if (!\array_key_exists($attributeName, $this->getDocumentSchema())) {
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

        foreach ($this->getFilterableAttributes() as $attributeName) {
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
        $filterableAndSortable = $this->getFilterableAndSortableAttributes();
        $result = [];

        foreach ($filterableAndSortable as $attributeName) {
            if (!LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public function getSingleFilterableAttributes(): array
    {
        $filterable = $this->getFilterableAttributes();
        $result = [];

        foreach ($filterable as $attributeName) {
            if (!LoupeTypes::isSingleType($this->getLoupeTypeForAttribute($attributeName))) {
                continue;
            }

            $result[] = $attributeName;
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    public function getSortableAttributes(): array
    {
        return array_flip(array_intersect_key(array_flip($this->engine->getConfiguration()->getSortableAttributes()), $this->getDocumentSchema()));
    }

    public function isMultiFilterableAttribute(string $attribute): bool
    {
        return \in_array($attribute, $this->getMultiFilterableAttributes(), true);
    }

    public function isNumericAttribute(string $attribute): bool
    {
        return LoupeTypes::isFloatType($this->getLoupeTypeForAttribute($attribute));
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

        return $this->needsSetup = !$this->engine->getConnection()->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [self::TABLE_NAME_INDEX_INFO]
        );
    }

    private function addDocumentsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_DOCUMENTS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true)
            ->setAutoincrement(true)
        ;

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
                LoupeTypes::TYPE_NULL => Types::STRING, // Null is represented as our internal string as well
                LoupeTypes::TYPE_STRING => Types::STRING,
                LoupeTypes::TYPE_NUMBER => Types::FLOAT,
                LoupeTypes::TYPE_BOOLEAN => Types::FLOAT,
                default => null
            };

            if ($dbalType === null) {
                continue;
            }

            $columns[$attribute] = $dbalType;
        }

        // We store the count for multi attributes to distinguish between null, empty and has data for the IS NULL
        // and IS EMPTY filters
        foreach ($this->getMultiFilterableAttributes() as $attribute) {
            $columns[$attribute] = Types::FLOAT;
        }

        foreach ($columns as $attribute => $dbalType) {
            $table->addColumn($attribute, $dbalType)
                ->setNotnull(true)
                ->setDefault(LoupeTypes::VALUE_NULL)
            ;

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
            ->setNotnull(true)
            ->setAutoincrement(true)
        ;

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

    private function addPrefixesToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_PREFIXES);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true)
            ->setAutoincrement(true)
        ;

        $table->addColumn('prefix', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('length', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('state', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['prefix', 'length']);
        $table->addIndex(['state']);
        $table->addIndex(['length']);
    }

    private function addPrefixesToTermsRelationToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_PREFIXES_TERMS);

        $table->addColumn('prefix', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('term', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['prefix', 'term']);
    }

    private function addStateSetToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_STATE_SET);

        $table->addColumn('state', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['state']);
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
        $table->addIndex(['position']);
    }

    private function addTermsToSchema(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE_NAME_TERMS);

        $table->addColumn('id', Types::INTEGER)
            ->setNotnull(true)
            ->setAutoincrement(true)
        ;

        $table->addColumn('term', Types::STRING)
            ->setNotnull(true);

        $table->addColumn('state', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('length', Types::INTEGER)
            ->setNotnull(true);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['term', 'state', 'length']);
        $table->addIndex(['state']);
        $table->addIndex(['length']);
    }

    private function getSchema(): Schema
    {
        $schema = new Schema();

        $this->addIndexInfoToSchema($schema);
        $this->addDocumentsToSchema($schema);
        $this->addMultiAttributesToSchema($schema);
        $this->addTermsToSchema($schema);
        $this->addPrefixesToSchema($schema);
        $this->addStateSetToSchema($schema);

        $this->addMultiAttributesToDocumentsRelationToSchema($schema);
        $this->addTermsToDocumentsRelationToSchema($schema);
        $this->addPrefixesToTermsRelationToSchema($schema);

        return $schema;
    }

    /**
     * @param array<string, mixed> $documentSchema
     */
    private function updateDocumentSchema(array $documentSchema): void
    {
        $this->documentSchema = $documentSchema;

        $this->updateSchema();

        $this->engine->upsert(self::TABLE_NAME_INDEX_INFO, [
            'key' => 'documentSchema',
            'value' => json_encode($documentSchema),
        ], ['key']);
    }

    private function updateSchema(): void
    {
        $schemaManager = $this->engine->getConnection()
            ->createSchemaManager();
        $comparator = $schemaManager->createComparator();

        $schemaDiff = $comparator->compareSchemas($schemaManager->introspectSchema(), $this->getSchema());
        $schemaManager->alterSchema($schemaDiff);
    }
}
