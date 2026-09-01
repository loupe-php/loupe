<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Doctrine\DBAL\Exception;
use Loupe\Loupe\Internal\ConnectionPool;

class BulkUpserterFactory
{
    /**
     * JSON transport removes SQLite's variable limit, but larger chunks were slower in the full indexing benchmark
     * and require larger temporary JSON strings. Keep the established limit as a memory-conscious batch-size target.
     */
    public const VARIABLE_LIMIT = 999;

    private readonly bool $jsonEachAvailable;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        $this->jsonEachAvailable = $this->detectJsonEach();
    }

    public function create(BulkUpsertConfig $bulkUpsertConfig): BulkUpserter
    {
        return new BulkUpserter(
            $this->connectionPool->loupeConnection,
            $bulkUpsertConfig,
            self::VARIABLE_LIMIT,
            $this->jsonEachAvailable,
        );
    }

    private function detectJsonEach(): bool
    {
        try {
            return 1 === (int) $this->connectionPool->loupeConnection
                ->fetchOne("SELECT value FROM json_each('[1]')")
            ;
        } catch (Exception) {
            return false;
        }
    }
}
