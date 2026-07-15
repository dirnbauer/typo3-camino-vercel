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

$secret = getenv('CRON_SECRET');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $name => $value) {
        if (strcasecmp((string)$name, 'Authorization') === 0) {
            $authorization = (string)$value;
            break;
        }
    }
}

if (!is_string($secret) || $secret === '') {
    http_response_code(503);
    echo "CRON_SECRET is not configured.\n";
    exit;
}
if (!hash_equals('Bearer ' . $secret, (string)$authorization)) {
    http_response_code(401);
    echo "Unauthorized.\n";
    exit;
}

/** @return array{output: string, exitCode: int} */
function runTypo3Command(string $root, string $commandName, string ...$arguments): array
{
    $command = [$root . '/vendor/bin/typo3', $commandName, ...$arguments];
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
        throw new RuntimeException('Failed to start TYPO3 CLI command.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [
        'output' => is_string($output) ? $output : '',
        'exitCode' => proc_close($process),
    ];
}

try {
    $setup = runTypo3Command($root, 'extension:setup', '--no-interaction');
    $demo = $setup['exitCode'] === 0
        ? runTypo3Command($root, 'webconsulting:camino-demo:setup', '--flush-caches')
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
