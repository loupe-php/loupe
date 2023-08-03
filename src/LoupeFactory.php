<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Search\Highlighter\Highlighter;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;

class LoupeFactory
{
    public function create(string $dbPath, Configuration $configuration): Loupe
    {
        if (! file_exists($dbPath)) {
            throw InvalidConfigurationException::becauseInvalidDbPath($dbPath);
        }

        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://notused:inthis@case/' . realpath($dbPath),
        ]), $configuration);
    }

    public function createInMemory(Configuration $configuration): Loupe
    {
        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://:memory:',
        ]), $configuration);
    }

    private function createFromConnection(Connection $connection, Configuration $configuration): Loupe
    {
        $tokenizer = new Tokenizer();

        if ($logger = $configuration->getLogger()) {
            $connection->getConfiguration()->setMiddlewares([
                new Middleware($logger)
            ]);
        }

        return new Loupe(
            new Engine(
                $connection,
                $configuration,
                $tokenizer,
                new Highlighter($configuration, $tokenizer),
                new Parser()
            )
        );
    }
}
