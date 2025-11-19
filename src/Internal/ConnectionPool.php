<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal;

use Doctrine\DBAL\Connection;

class ConnectionPool
{
    public function __construct(
        public Connection $loupeConnection,
        public Connection $ticketConnection,
    ) {

    }
}
