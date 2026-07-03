<?php

declare(strict_types=1);

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

$root = dirname(__DIR__, 3);
$command = [
    $root . '/vendor/bin/typo3',
    'scheduler:run',
    '--no-interaction',
];

$process = proc_open(
    $command,
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
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
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);
http_response_code($exitCode === 0 ? 200 : 500);
header('Content-Type: text/plain; charset=utf-8');

if ($stdout !== false && $stdout !== '') {
    echo $stdout;
}
if ($stderr !== false && $stderr !== '') {
    echo $stderr;
}
if ($stdout === '' && $stderr === '') {
    echo $exitCode === 0 ? "TYPO3 scheduler finished.\n" : "TYPO3 scheduler failed.\n";
}
