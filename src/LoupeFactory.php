<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Terminal42\Loupe\Internal\Configuration;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;

class LoupeFactory
{
    public function create(string $dbPath, array $configuration): Loupe
    {
        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://notused:inthis@case/' . realpath($dbPath),
        ]), $configuration);
    }

    public function createInMemory(array $configuration): Loupe
    {
        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://:memory:',
        ]), $configuration);
    }

    private function createFromConnection(Connection $connection, array $configuration): Loupe
    {
        $configuration = new Configuration($configuration);

        return new Loupe(new Engine($connection, $configuration, new Tokenizer(), new Parser()));
    }
}
