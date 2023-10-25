<?php

use Loupe\Loupe\SearchParameters;

$config = require_once __DIR__ . '/config.php';

$startTime = microtime(true);

$searchParameters = SearchParameters::create()
    ->withQuery('Amakin Dkywalker')
;

print_r($config['loupe']->search($searchParameters)->toArray());

echo sprintf('Finished: %.2F MiB - %.2F s', memory_get_peak_usage(true) / 1024 / 1024, microtime(true) - $startTime);
