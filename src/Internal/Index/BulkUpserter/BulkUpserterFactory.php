<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Loupe\Loupe\Internal\ConnectionPool;

class BulkUpserterFactory
{
    public function __construct(
        private ConnectionPool $connectionPool
    ) {

    }

    public function create(BulkUpsertConfig $bulkUpsertConfig): BulkUpserter
    {
        return new BulkUpserter($this->connectionPool->loupeConnection, $bulkUpsertConfig);
    }
}
