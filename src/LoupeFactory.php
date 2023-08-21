<?php

declare(strict_types=1);

namespace Loupe\Loupe;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Loupe\Loupe\Exception\InvalidConfigurationException;
use Loupe\Loupe\Internal\Engine;
use Loupe\Loupe\Internal\Filter\Parser;
use Loupe\Loupe\Internal\Search\Highlighter\Highlighter;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Nitotm\Eld\LanguageDetector;

class LoupeFactory
{
    public function create(string $dbPath, Configuration $configuration): Loupe
    {
        if (!file_exists($dbPath)) {
            throw InvalidConfigurationException::becauseInvalidDbPath($dbPath);
        }

        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://notused:inthis@case/' . realpath($dbPath),
        ], $this->getDbalConfiguration($configuration)), $configuration);
    }

    public function createInMemory(Configuration $configuration): Loupe
    {
        return $this->createFromConnection(DriverManager::getConnection([
            'url' => 'pdo-sqlite://:memory:',
        ], $this->getDbalConfiguration($configuration)), $configuration);
    }

    private function createFromConnection(Connection $connection, Configuration $configuration): Loupe
    {
        $tokenizer = $this->createTokenizer($configuration);

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

    private function createTokenizer(Configuration $configuration): Tokenizer
    {
        $languageDetector = new LanguageDetector();
        $languageDetector->cleanText(true); // Clean stuff like URLs, domains etc. to improve language detection

        if ($configuration->getLanguages() !== []) {
            $languageDetector->langSubset($configuration->getLanguages()); // Save subset
        }

        return new Tokenizer($languageDetector);
    }

    private function getDbalConfiguration(Configuration $configuration): DbalConfiguration
    {
        $config = new DbalConfiguration();

        if ($configuration->getLogger() !== null) {
            $config->setMiddlewares([
                new Middleware($configuration->getLogger()),
            ]);
        }

        return $config;
    }
}
