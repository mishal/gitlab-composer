<?php

require __DIR__ . '/../vendor/autoload.php';

use ExtendedGitlab\Client;
use Gitlab\Exception\RuntimeException;

$packages_file = __DIR__ . '/../cache/packages.json';

/**
 * Output a json file, sending max-age header, then dies
 */
$outputFile = function ($file) {
    $mtime = filemtime($file);

    header('Content-Type: application/json');
    header('Last-Modified: ' . gmdate('r', $mtime));
    header('Cache-Control: max-age=0');

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && ($since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) && $since >= $mtime) {
        header('HTTP/1.0 304 Not Modified');
    } else {
        readfile($file);
    }
    die();
};

if (!isset($_GET['refresh']) && is_readable($packages_file)) {
    $outputFile($packages_file);
}

$confs = include __DIR__ . '/_bootstrap.php';

$client = new Client($confs['endpoint']);
$client->authenticate($confs['api_key'], Client::AUTH_URL_TOKEN);

$projects = $client->api('projects');
$repos = $client->api('repositories');

$validMethods = array('ssh', 'http');
if (isset($confs['method']) && in_array($confs['method'], $validMethods)) {
    define('method', $confs['method']);
} else {
    define('method', 'ssh');
}

/**
 * Retrieves some information about a project's composer.json
 *
 * @param array $project
 * @param string $ref commit id
 * @return array|false
 */
$fetch_composer = function ($project, $ref) use ($repos) {

    try {
        $c = $repos->getFile($project['id'], 'composer.json', $ref);
        $content = base64_decode($c['content']);
        $composer = json_decode($content, true);

        if (empty($composer['name']) || $composer['name'] != $project['path_with_namespace']) {
            return false; // packages must have a name and must match
        }

        return $composer;
    } catch (RuntimeException $e) {
        return false;
    }
};

/**
 * Retrieves some information about a project for a specific ref
 *
 * @param array $project
 * @param string $ref commit id
 * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
 */
$fetch_ref = function ($project, $ref) use ($fetch_composer) {
    if (preg_match('/^v?\d+\.\d+(\.\d+)*(\-(dev|patch|alpha|beta|RC)\d*)?$/', $ref['name'])) {
        $version = $ref['name'];
    } else {
        $version = 'dev-' . $ref['name'];
    }

    if (($data = $fetch_composer($project, $ref['commit']['id'])) !== false) {
        $data['version'] = $version;
        $data['source'] = array(
            'type' => 'git',
            'url' => $project[method . '_url_to_repo'],
            'reference' => $ref['commit']['id'],
        );
        // redirect
        $path = (isset($_SERVER['HTTPS']) ? 'https:' : 'http:') . '//' . $_SERVER['HTTP_HOST'] . ltrim(dirname($_SERVER['PHP_SELF'], '/'));
        $downloadUrl = $path . '/download.php?' . http_build_query([
            'id' => $project['id'],
            'ref' => $ref['commit']['id']
        ]);
        $data['dist'] = array(
            'type' => 'tar',
            'url' => $downloadUrl,
        );
        return array($version => $data);
    } else {
        return array();
    }
};

/**
 * Retrieves some information about a project for all refs
 * @param array $project
 * @return array   Same as $fetch_ref, but for all refs
 */
$fetch_refs = function ($project) use ($fetch_ref, $repos) {
    $datas = array();

    try {
        foreach (array_merge($repos->branches($project['id']), $repos->tags($project['id'])) as $ref) {
            foreach ($fetch_ref($project, $ref) as $version => $data) {
                $datas[$version] = $data;
            }
        }
    } catch (RuntimeException $e) {
        // The repo has no commits — skipping it.
    }

    return $datas;
};

/**
 * Caching layer on top of $fetch_refs
 * Uses last_activity_at from the $project array, so no invalidation is needed
 *
 * @param array $project
 * @return array Same as $fetch_refs
 */
$load_data = function ($project) use ($fetch_refs) {
    $file = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
    $mtime = strtotime($project['last_activity_at']);

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }

    if (file_exists($file) && filemtime($file) >= $mtime) {
        if (filesize($file) > 0) {
            return json_decode(file_get_contents($file));
        } else {
            return false;
        }
    } elseif ($data = $fetch_refs($project)) {
        file_put_contents($file, json_encode($data));
        touch($file, $mtime);

        return $data;
    } else {
        $f = fopen($file, 'w');
        fclose($f);
        touch($file, $mtime);

        return false;
    }
};

$all_projects = array();
$mtime = 0;

$me = $client->api('users')->me();
if ((bool)$me['is_admin']) {
    $projects_api_method = 'all';
} else {
    $projects_api_method = 'accessible';
}

for ($page = 1; count($p = $projects->$projects_api_method($page, 100)); $page++) {
    foreach ($p as $project) {
        $all_projects[] = $project;
        $mtime = max($mtime, strtotime($project['last_activity_at']));
    }
}

if (!file_exists($packages_file) || filemtime($packages_file) < $mtime) {
    $packages = array();
    foreach ($all_projects as $project) {
        if ($package = $load_data($project)) {
            $packages[$project['path_with_namespace']] = $package;
        }
    }
    $data = json_encode(array(
        'packages' => array_filter($packages),
    ));

    file_put_contents($packages_file, $data);
}

$outputFile($packages_file);
