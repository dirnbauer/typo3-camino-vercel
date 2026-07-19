<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';
typo3_vercel_export_request_oidc_token();

header('Cache-Control: no-store, private');
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo "Use POST with the CRON_SECRET Bearer token.\n";
    exit;
}

typo3_vercel_require_cron_secret();

try {
    $setup = typo3_vercel_run_typo3_command(['extension:setup', '--no-interaction']);
    $demo = $setup['exitCode'] === 0
        ? typo3_vercel_run_typo3_command(['webconsulting:camino-demo:setup', '--flush-caches'])
        : ['output' => '', 'exitCode' => 1];
    $output = $setup['output'] . $demo['output'];
    $exitCode = $demo['exitCode'];
} catch (Throwable $exception) {
    http_response_code(500);
    echo $exception->getMessage() . "\n";
    exit;
}

http_response_code($exitCode === 0 ? 200 : 500);
echo $output !== '' ? $output : ($exitCode === 0 ? "Camino demo setup finished.\n" : "Camino demo setup failed.\n");
