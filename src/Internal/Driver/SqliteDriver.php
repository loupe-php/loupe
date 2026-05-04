<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Driver;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\PDO\Connection;
use Pdo\Sqlite;

/**
 * Forces \Pdo\Sqlite when available so createFunction() is reliably exposed.
 *
 * Some PHP 8.4 builds (Debian/Ondrej) strip PDO::sqliteCreateFunction() ahead
 * of its 8.5 removal AND PDO::connect("sqlite:") returns plain PDO instead of
 * the \Pdo\Sqlite subclass — leaving Loupe with no way to register functions.
 */
final class SqliteDriver extends AbstractSQLiteDriver
{
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        $dsn = 'sqlite:'.($params['path'] ?? (!empty($params['memory']) ? ':memory:' : ''));

        $pdo = class_exists(Sqlite::class)
            ? Sqlite::connect($dsn)
            : new \PDO($dsn);

        return new Connection($pdo);
    }
}
