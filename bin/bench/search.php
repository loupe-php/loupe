<?php

use Loupe\Loupe\SearchParameters;

$config = require_once __DIR__ . '/../config.php';

$options = getopt('q::d', ['query::', 'debug']);
$query = $options['q'] ?? $options['query'] ?? 'Amakin Dkywalker';
$debug = isset($options['d']) || isset($options['debug']);

$startTime = microtime(true);

$searchParameters = SearchParameters::create()
    ->withQuery($query)
;

$result = $config['loupe']->search($searchParameters);

$endTime = microtime(true) - $startTime;
$memoryUsage = memory_get_peak_usage(true) / 1024 / 1024;

if ($debug) {
    print_r($result->toArray());
}

echo sprintf('Searched in %.2F ms using %.2F MiB', $endTime, $memoryUsage);
echo PHP_EOL;

if ($config['logger']) {
    foreach ($config['logger']->getRecords() as $record) {
        ray($record);
    }
}
