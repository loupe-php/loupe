<?php

declare(strict_types=1);

use Loupe\Loupe\Tests\Benchmark\DatabaseSizeCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\SingleCommandApplication;

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new SingleCommandApplication())
    ->setName('loupe-database-size')
    ->setDescription('Reports the storage used by a Loupe SQLite database.')
    ->addArgument('database', InputArgument::REQUIRED, 'Path to the SQLite database.')
    ->setCode(new DatabaseSizeCommand())
    ->run()
;
