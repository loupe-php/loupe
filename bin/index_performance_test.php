<?php

namespace App;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

require_once 'vendor/autoload.php';

$movies = __DIR__ . '/movies.json';

if (!file_exists($movies)) {
    echo 'movies.json does not exist. Run "wget https://www.meilisearch.com/movies.json -O bin/movies.json" first.';
    exit(1);
}

// Index everything (do not define the searchable fields which would speed up the process)
$configuration = Configuration::create();

$loupeFactory = new LoupeFactory();
$loupe = $loupeFactory->createInMemory($configuration);

$startTime = microtime(true);

$loupe->addDocuments(json_decode(file_get_contents($movies), true));

echo 'Finished! Time: ' . round(microtime(true) - $startTime, 2).'s';


