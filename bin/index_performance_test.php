<?php

$config = require_once __DIR__ . '/config.php';

$startTime = microtime(true);

$config['loupe']->addDocuments(json_decode(file_get_contents($config['movies']), true));

echo sprintf('Finished: %.2F MiB - %.2F s', memory_get_peak_usage(true) / 1024 / 1024, microtime(true) - $startTime);


