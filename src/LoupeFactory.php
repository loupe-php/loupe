<?php

namespace Terminal42\Loupe;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Internal\Configuration;
use Terminal42\Loupe\Internal\Engine;
use Terminal42\Loupe\Internal\Filter\Parser;

class LoupeFactory
{
    public function create(string $dbPath, array $configuration): Loupe
    {
        $configuration = new Configuration($configuration);
        $dsn = 'pdo-sqlite://notused:inthis@case/' . realpath($dbPath);

        return new Loupe(new Engine(DriverManager::getConnection(['url' => $dsn]), $configuration, new Parser()));
    }
}