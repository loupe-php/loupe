<?php

namespace App;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;

require_once 'vendor/autoload.php';

$db = __DIR__ . '/../var/test2.db';

if (!file_exists($db)) {
    echo 'var/test.db does not exist. Run "php bin/index_performance_test.php" first.';
    exit(1);
}

// Search everything with default typo tolerance enabled
$configuration = Configuration::create();

$loupeFactory = new LoupeFactory();
$loupe = $loupeFactory->create(__DIR__ . '/../var/test.db', $configuration);

$startTime = microtime(true);

$searchParameters = SearchParameters::create()
    ->withQuery('Amakin Dkywalker')
;

print_r($loupe->search($searchParameters)->toArray());

echo sprintf('Finished: %.2F MiB - %.2F s', memory_get_peak_usage(true) / 1024 / 1024, microtime(true) - $startTime);
