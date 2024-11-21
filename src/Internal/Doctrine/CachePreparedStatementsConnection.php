<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Statement;

final class CachePreparedStatementsConnection extends AbstractConnectionMiddleware
{
    private array $cachedStatements = [];

    public function prepare(string $sql): Statement
    {
        if (isset($this->cachedStatements[$sql])) {
            return $this->cachedStatements[$sql];
        }

        return $this->cachedStatements[$sql] = parent::prepare($sql);
    }
}
