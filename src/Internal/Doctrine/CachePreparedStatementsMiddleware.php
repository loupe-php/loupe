<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

class CachePreparedStatementsMiddleware implements MiddlewareInterface
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new CachePreparedStatementsDriver($driver);
    }
}
