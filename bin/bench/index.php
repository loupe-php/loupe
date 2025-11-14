<?php

$config = require_once __DIR__ . '/../config.php';

$options = getopt('l::du', ['limit::', 'debug', 'update', 'no-delete']);
$limit = intval($options['l'] ?? $options['limit'] ?? 0);
$debug = isset($options['d']) || isset($options['debug']);
$update = isset($options['u']) || isset($options['update']);
$noDelete = isset($options['no-delete']);

$movies = json_decode(file_get_contents($config['movies']), true);
if ($limit > 0) {
    $movies = array_slice($movies, 0, $limit);
}

if (!$noDelete) {
    $config['loupe']->deleteAllDocuments();
}

$startTime = microtime(true);

$config['loupe']->addDocuments($movies);

if ($update) {
    $config['loupe']->addDocuments($movies);
}

echo sprintf('Indexed in %.2F s using %.2F MiB', microtime(true) - $startTime, memory_get_peak_usage(true) / 1024 / 1024);
echo PHP_EOL;
