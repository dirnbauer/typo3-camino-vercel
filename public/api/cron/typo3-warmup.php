<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/scripts/runtime-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!typo3_vercel_health_authorized()) {
    http_response_code(getenv('CRON_SECRET') ? 401 : 503);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized.'], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

typo3_vercel_export_request_oidc_token();
$startedAt = microtime(true);
$checks = [
    'database' => typo3_vercel_health_database(),
    'redis' => typo3_vercel_health_redis(),
    'typo3_frontend' => typo3_vercel_health_typo3_loopback(20.0, '/'),
    'typo3_backend' => typo3_vercel_health_typo3_loopback(20.0, '/typo3/'),
    'solr' => typo3_vercel_health_solr(25.0),
];
$ok = typo3_vercel_health_checks_ok($checks);
$response = [
    'status' => $ok ? 'ok' : 'error',
    'service' => 'typo3-warmup',
    'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    'checks' => $checks,
];

error_log(json_encode([
    'level' => $ok ? 'info' : 'error',
    'component' => 'warmup',
    'event' => 'completed',
    ...$response,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
http_response_code($ok ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
