<?php

$config = require_once __DIR__ . '/../config.php';

$options = getopt('l::du', ['limit::', 'debug', 'update']);
$limit = intval($options['l'] ?? $options['limit'] ?? 0);
$debug = isset($options['d']) || isset($options['debug']);
$update = isset($options['u']) || isset($options['update']);

$movies = json_decode(file_get_contents($config['movies']), true);
if ($limit > 0) {
    $movies = array_slice($movies, 0, $limit);
}

$config['loupe']->deleteAllDocuments();

$startTime = microtime(true);

foreach (array_chunk($movies, 10000) as $moviesChunk) {
    $config['loupe']->addDocuments($moviesChunk);
}

foreach (array_chunk($movies, 10000) as $moviesChunk) {
    if ($update) {
        $config['loupe']->addDocuments($moviesChunk);
    }
}

echo sprintf('Indexed in %.2F s using %.2F MiB', microtime(true) - $startTime, memory_get_peak_usage(true) / 1024 / 1024);
echo PHP_EOL;
