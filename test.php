<?php

$reflector = new \ReflectionExtension('pdo_sqlite');

ob_start();
$reflector->info();

var_dump(ob_get_clean());