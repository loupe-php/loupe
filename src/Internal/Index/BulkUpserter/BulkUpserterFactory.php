<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Index\BulkUpserter;

use Loupe\Loupe\Internal\ConnectionPool;

class BulkUpserterFactory
{
    public const FALLBACK_VARIABLE_LIMIT = 999;

    private int|null $variableLimit = null;

    public function __construct(
        private ConnectionPool $connectionPool
    ) {

    }

    public function create(BulkUpsertConfig $bulkUpsertConfig): BulkUpserter
    {
        return new BulkUpserter($this->connectionPool->loupeConnection, $bulkUpsertConfig, $this->getVariableLimit());
    }

    private function getVariableLimit(): int
    {
        if ($this->variableLimit !== null) {
            return $this->variableLimit;
        }

        try {
            $pragma = $this->connectionPool->loupeConnection
                ->executeQuery("SELECT * FROM pragma_compile_options WHERE compile_options LIKE 'MAX_VARIABLE_NUMBER=%'")
                ->fetchOne()
            ;

            preg_match('/^MAX_VARIABLE_NUMBER=(\d+)$/', (string) $pragma, $matches);

            return $this->variableLimit = isset($matches[1]) ? ((int) $matches[1]) : self::FALLBACK_VARIABLE_LIMIT;
        } catch (\Throwable) {
            // noop
        }

        return $this->variableLimit = self::FALLBACK_VARIABLE_LIMIT;
    }
}
