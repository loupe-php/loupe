<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class CachePreparedStatementsDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new CachePreparedStatementsConnection(
            parent::connect($params),
        );
    }
}
