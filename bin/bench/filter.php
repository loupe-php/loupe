<?php

use Loupe\Loupe\SearchParameters;

$config = require_once __DIR__ . '/../config.php';

$options = getopt('d', ['debug']);
$debug = isset($options['d']) || isset($options['debug']);

$startTime = microtime(true);

$searchParameters = SearchParameters::create()
    ->withFilter("release_date < 1127433600 AND genres IN ('Drama', 'Western')")
    ->withSort(['release_date:desc'])
;

$result = $config['loupe']->search($searchParameters);

if ($debug) {
    print_r($result->toArray());
}

echo sprintf('Searched in %.2F ms using %.2F MiB', (microtime(true) - $startTime) * 1000, memory_get_peak_usage(true) / 1024 / 1024);
echo PHP_EOL;
