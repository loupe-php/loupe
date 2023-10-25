<?php


use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$movies = __DIR__ . '/../var/movies.json';
$db = __DIR__ . '/../var/movies-test';

if (!file_exists($movies)) {
    echo 'movies.json does not exist. Run "wget https://www.meilisearch.com/movies.json -O var/movies.json" first.';
    exit(1);
}

// Only index the title and the overview. There's no point in performance tests for unrealistic scenarios. If you want
// fast search results, do not index irrelevant stuff.
$configuration = Configuration::create()
    ->withSearchableAttributes(['title', 'overview'])
    ->withLanguages(['en'])
;

$loupeFactory = new LoupeFactory();

return [
    'movies' => $movies,
    'loupe' => $loupeFactory->create($db, $configuration),
];