<?php

require __DIR__ . '/../vendor/autoload.php';

// See ../confs/samples/gitlab.ini
$config_file = __DIR__ . '/../confs/gitlab.ini';

if (!file_exists($config_file)) {
    header('HTTP/1.0 500 Internal Server Error');
    die('confs/gitlab.ini missing');
}

$confs = parse_ini_file($config_file);

define('END_POINT', $confs['endpoint']);

return $confs;
