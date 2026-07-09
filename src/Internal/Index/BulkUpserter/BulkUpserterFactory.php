<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Loupe\Loupe\Internal\ConnectionPool;

class BulkUpserterFactory
{
    /**
     * We tried using a higher variable limit fetching it dynamically using
     * > SELECT * FROM pragma_compile_options WHERE compile_options LIKE 'MAX_VARIABLE_NUMBER=%'
     * but there's no real positive effect. Longer UPSERT queries will be faster indeed but only by
     * a neglectable amount of time. They will, however, use a lot more memory for the prepared
     * statements. Hence, we hard code it to 999 which is also a limit that is supported in all
     * SQLite configurations.
     */
    public const VARIABLE_LIMIT = 999;

    /**
     * @param string|null $sqliteVersion Automatically detected if null, can be overridden for testing purposes
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        private string|null $sqliteVersion = null
    ) {

    }

    public function create(BulkUpsertConfig $bulkUpsertConfig): BulkUpserter
    {
        return new BulkUpserter($this->connectionPool->loupeConnection, $bulkUpsertConfig, self::VARIABLE_LIMIT, $this->getSqliteVersion());
    }

    private function getSqliteVersion(): string
    {
        return $this->sqliteVersion ??= (string) $this->connectionPool->loupeConnection->fetchOne('SELECT sqlite_version()');
    }
}
