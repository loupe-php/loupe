<?php

$config = require_once __DIR__ . '/../config.php';

$options = getopt('l::d', ['limit::', 'debug']);
$limit = intval($options['l'] ?? $options['limit'] ?? 0);
$debug = isset($options['d']) || isset($options['debug']);

$movies = json_decode(file_get_contents($config['movies']), true);
if ($limit > 0) {
    $movies = array_slice($movies, 0, $limit);
}

$config['loupe']->deleteAllDocuments();

$startTime = microtime(true);

$config['loupe']->addDocuments($movies);

echo sprintf('Indexed in %.2F s using %.2F MiB', microtime(true) - $startTime, memory_get_peak_usage(true) / 1024 / 1024);
echo PHP_EOL;
