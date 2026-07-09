<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/scripts/typo3-env.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/scripts/runtime-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$deep = isset($_GET['deep']) && in_array(strtolower((string)$_GET['deep']), ['1', 'true', 'yes', 'on'], true);
$response = [
    'status' => 'ok',
    'service' => 'typo3',
    'revision' => substr((string)(getenv('VERCEL_GIT_COMMIT_SHA') ?: 'local'), 0, 12),
    'region' => (string)(getenv('VERCEL_REGION') ?: 'local'),
    'php' => PHP_VERSION,
];

if ($deep) {
    if (!typo3_vercel_health_authorized()) {
        http_response_code(getenv('CRON_SECRET') ? 401 : 503);
        echo json_encode(['status' => 'error', 'error' => 'Deep health check is not authorized.'], JSON_THROW_ON_ERROR);
        exit;
    }

    typo3_vercel_export_request_oidc_token();
    $writeProbe = isset($_GET['write']) && in_array(strtolower((string)$_GET['write']), ['1', 'true', 'yes', 'on'], true);
    $checks = [
        'database' => typo3_vercel_health_database(),
        'redis' => typo3_vercel_health_redis(),
        'blob' => typo3_vercel_health_blob($writeProbe),
        'solr' => typo3_vercel_health_solr(20.0),
        'filesystem' => typo3_vercel_health_measure(static function (): array {
            foreach (['/tmp/typo3', '/tmp/typo3/var', '/tmp/typo3/fileadmin', '/tmp/typo3/typo3temp'] as $path) {
                if (!is_dir($path) || !is_writable($path)) {
                    throw new RuntimeException('Runtime path is not writable: ' . $path);
                }
            }
            return [];
        }),
    ];
    $response['checks'] = $checks;
    $response['status'] = typo3_vercel_health_checks_ok($checks) ? 'ok' : 'error';
    http_response_code($response['status'] === 'ok' ? 200 : 503);
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
