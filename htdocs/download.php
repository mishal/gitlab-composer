<?php

use ExtendedGitlab\Client;

// error_reporting(E_ALL);
// ini_set('display_errors', 1);

if (!isset($_GET['id']) || !isset($_GET['ref'])) {
    http_response_code(500);
    echo 'Missing `id` or/and `ref` parameter';
    exit;
}

if (!isset($_SERVER['PHP_AUTH_USER'])) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Package download"');
    exit;
}

$apiToken = $_SERVER['PHP_AUTH_PW'];

if (!isset($apiToken)) {
    http_response_code(500);
    echo 'Missing `password` header, setup your auth.json!';
    exit;
}

$confs = include __DIR__ . '/_bootstrap.php';

$id = $_GET['id'];
$ref = $_GET['ref'];
$cacheFile = __DIR__ . '/../cache/dist/' . md5($id . $ref) . '.tar.gz';

// we always authenticate!
$client = new Client($confs['endpoint']);
$client->authenticate($apiToken, Client::AUTH_HTTP_TOKEN);
$projects = $client->api('projects');

try {
    $projects->show($id);
} catch (\Gitlab\Exception\RuntimeException $e) {
    http_response_code(500);
    echo 'Invalid credentials or project does not exist';
    exit;
}

if (!file_exists($cacheFile)) {
    $archive = $projects->archive($id, $ref);
    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0775, true);
    }
    file_put_contents($cacheFile, $archive);
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($cacheFile));
header('Content-Disposition: attachment; filename=archive.tar.gz');
readfile($cacheFile);
