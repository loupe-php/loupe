<?php

declare(strict_types=1);

namespace Terminal42\Loupe;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Terminal42\Loupe\Exception\InvalidConfigurationException;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Parser;
use Terminal42\Loupe\Internal\Search\Highlighter\Highlighter;
use Terminal42\Loupe\Internal\Tokenizer\Tokenizer;

class LoupeFactory
{
    public function create(string $dbPath, array $configuration): Loupe
    {
        if (! file_exists($dbPath)) {
            throw InvalidConfigurationException::becauseInvalidDbPath($dbPath);
        }

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
        $tokenizer = new Tokenizer();

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
