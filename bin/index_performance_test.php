<?php

namespace App;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

require_once 'vendor/autoload.php';

$movies = __DIR__ . '/../var/movies.json';
$db = __DIR__ . '/../var/test.db';

if (!file_exists($movies)) {
    echo 'movies.json does not exist. Run "wget https://www.meilisearch.com/movies.json -O var/movies.json" first.';
    exit(1);
}

// Index everything (do not define the searchable fields which would speed up the process)
$configuration = Configuration::create();

$loupeFactory = new LoupeFactory();
$loupe = $loupeFactory->create($db, $configuration);

$startTime = microtime(true);

$loupe->addDocuments(json_decode(file_get_contents($movies), true));

echo sprintf('Finished: %.2F MiB - %.2F s', memory_get_peak_usage(true) / 1024 / 1024, microtime(true) - $startTime);


