<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';
typo3_vercel_export_request_oidc_token();

$secret = getenv('CRON_SECRET');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $authorization = (string)$value;
                break;
            }
        }
    }
}

if ($secret === false || $secret === '') {
    http_response_code(503);
    echo "CRON_SECRET is not configured.\n";
    exit;
}

if (!hash_equals('Bearer ' . $secret, $authorization)) {
    http_response_code(401);
    echo "Unauthorized.\n";
    exit;
}

if (typo3_scheduler_should_skip_internal_solr_demo()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TYPO3 scheduler skipped for the internal Vercel Solr demo service.\n";
    echo "The internal demo Solr service self-seeds the Camino search index on startup, so running EXT:solr runtime indexing against it is intentionally disabled.\n";
    echo "Use managed/external Solr for production indexing, or set TYPO3_SOLR_RUN_INTERNAL_SCHEDULER=1 to force this demo task.\n";
    exit;
}

$command = [
    $root . '/vendor/bin/typo3',
    'scheduler:run',
    '--no-interaction',
];

foreach (typo3_scheduler_task_uids() as $taskUid) {
    $command[] = '--task=' . $taskUid;
}

if (typo3_scheduler_truthy_param('force') || typo3_scheduler_truthy_env('TYPO3_SCHEDULER_FORCE')) {
    $command[] = '--force';
}

// stderr is redirected into stdout so a single blocking read cannot deadlock:
// reading one pipe to EOF while the child fills a second, unread pipe past its
// ~64 KB buffer (e.g. a large task stack trace) would otherwise hang the request
// until the Vercel function times out.
$process = proc_open(
    $command,
    // @phpstan-ignore argument.type (PHP supports ['redirect', 1] descriptors.)
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['redirect', 1],
    ],
    $pipes,
    $root,
);

if (!is_resource($process)) {
    http_response_code(500);
    echo "Failed to start TYPO3 scheduler.\n";
    exit;
}

fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);

$exitCode = proc_close($process);
http_response_code($exitCode === 0 ? 200 : 500);
header('Content-Type: text/plain; charset=utf-8');

if ($output !== false && $output !== '') {
    echo $output;
} else {
    echo $exitCode === 0 ? "TYPO3 scheduler finished.\n" : "TYPO3 scheduler failed.\n";
}

function typo3_scheduler_should_skip_internal_solr_demo(): bool
{
    if (typo3_scheduler_truthy_param('runInternalSolr') || typo3_scheduler_truthy_env('TYPO3_SOLR_RUN_INTERNAL_SCHEDULER')) {
        return false;
    }

    return typo3_scheduler_has_internal_solr_service() && !typo3_scheduler_has_external_solr_connection();
}

function typo3_scheduler_has_internal_solr_service(): bool
{
    foreach (['TYPO3_SOLR_SERVICE_URL', 'SOLR_SERVICE_URL', 'TYPO3_SOLR_INTERNAL_URL', 'SOLR_INTERNAL_URL'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return true;
        }
    }

    return false;
}

function typo3_scheduler_has_external_solr_connection(): bool
{
    foreach (['TYPO3_SOLR_URL', 'SOLR_URL', 'TYPO3_SOLR_HOST', 'SOLR_HOST'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @return list<int>
 */
function typo3_scheduler_task_uids(): array
{
    $tasks = $_GET['task'] ?? [];
    if (!is_array($tasks)) {
        $tasks = [$tasks];
    }

    $taskUids = [];
    foreach ($tasks as $task) {
        if (is_numeric($task) && (int)$task > 0) {
            $taskUids[] = (int)$task;
        }
    }

    return array_values(array_unique($taskUids));
}

function typo3_scheduler_truthy_param(string $name): bool
{
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return false;
    }

    return in_array(strtolower(trim((string)$_GET[$name])), ['1', 'true', 'yes', 'on'], true);
}

function typo3_scheduler_truthy_env(string $name): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return false;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}
