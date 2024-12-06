<?php

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Logger\InMemoryLogger;
use Loupe\Loupe\LoupeFactory;

ini_set('memory_limit', '256M');

require_once __DIR__ . '/../vendor/autoload.php';

$movies = __DIR__ . '/../var/movies.json';
$dataDir = __DIR__ . '/../var/movies-test';
// $dataDir = __DIR__ . '/../var/movies-test-1k';

if (!file_exists($movies)) {
    echo 'movies.json does not exist. Run "wget https://www.meilisearch.com/movies.json -O var/movies.json" first.';
    exit(1);
}

// $logger = new InMemoryLogger();

// Only index the title and the overview. There's no point in performance tests for unrealistic scenarios. If you want
// fast search results, do not index irrelevant stuff.
$configuration = Configuration::create()
    ->withSearchableAttributes(['title', 'overview'])
    ->withLanguages(['en'])
    // ->withStopWords(['a', 'an', 'the', 'of', 'in', 'and', 'or', 'on', 'to', 'as', 'by', 'for', 'with', 'at'])
    // ->withLogger($logger)
;

$loupeFactory = new LoupeFactory();

return [
    'movies' => $movies,
    'loupe' => $loupeFactory->create($dataDir, $configuration),
    'logger' => $logger ?? null,
];
