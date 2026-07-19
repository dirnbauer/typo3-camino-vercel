<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';
typo3_vercel_export_request_oidc_token();

typo3_vercel_require_cron_secret();

if (typo3_scheduler_should_skip_internal_solr_demo()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TYPO3 scheduler skipped for the internal Vercel Solr demo service.\n";
    echo "The internal demo Solr service self-seeds the Camino search index on startup, so running EXT:solr runtime indexing against it is intentionally disabled.\n";
    echo "Use managed/external Solr for production indexing, or set TYPO3_SOLR_RUN_INTERNAL_SCHEDULER=1 to force this demo task.\n";
    exit;
}

$arguments = ['scheduler:run', '--no-interaction'];

foreach (typo3_scheduler_task_uids() as $taskUid) {
    $arguments[] = '--task=' . $taskUid;
}

if (typo3_scheduler_truthy_param('force') || typo3_scheduler_truthy_env('TYPO3_SCHEDULER_FORCE')) {
    $arguments[] = '--force';
}

try {
    ['output' => $output, 'exitCode' => $exitCode] = typo3_vercel_run_typo3_command($arguments);
} catch (RuntimeException) {
    http_response_code(500);
    echo "Failed to start TYPO3 scheduler.\n";
    exit;
}

http_response_code($exitCode === 0 ? 200 : 500);
header('Content-Type: text/plain; charset=utf-8');

if ($output !== '') {
    echo $output;
} else {
    echo $exitCode === 0 ? "TYPO3 scheduler finished.\n" : "TYPO3 scheduler failed.\n";
}

function typo3_scheduler_should_skip_internal_solr_demo(): bool
{
    if (typo3_scheduler_truthy_param('runInternalSolr') || typo3_scheduler_truthy_env('TYPO3_SOLR_RUN_INTERNAL_SCHEDULER')) {
        return false;
    }

    return typo3_vercel_solr_service_url() !== null && !typo3_scheduler_has_external_solr_connection();
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

    return typo3_vercel_truthy((string)$_GET[$name]);
}

function typo3_scheduler_truthy_env(string $name): bool
{
    $value = typo3_vercel_env($name);

    return $value !== null && typo3_vercel_truthy($value);
}
