<?php

namespace Terminal42\Loupe;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Config\Definition\Processor;
use Terminal42\Loupe\Internal\IndexManager;

class LoupeFactory
{
    public function create(string $dbPath, array $configuration): Loupe
    {
        $configuration = (new Processor())->process(
            Loupe::getConfigTreeBuilder()->buildTree(),
            [$configuration]
        );

        $dsn = 'pdo-sqlite://notused:inthis@case/' . realpath($dbPath);

        return new Loupe(new IndexManager(DriverManager::getConnection(['url' => $dsn]), $configuration));
    }
}