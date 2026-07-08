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
$action = isset($_GET['action']) ? (string)$_GET['action'] : 'setup';

if ($action === 'probe') {
    typo3_solr_probe();
    exit;
}

if ($action === 'logs') {
    typo3_solr_logs($root);
    exit;
}

if ($action === 'runtime') {
    typo3_solr_runtime($root);
    exit;
}

if (!in_array($action, ['setup', 'diagnose'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unsupported action. Use setup, diagnose, probe, logs, or runtime.\n";
    exit;
}

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$schedulerInterval = isset($_GET['schedulerInterval']) && is_numeric($_GET['schedulerInterval'])
    ? max(60, min(86400, (int)$_GET['schedulerInterval']))
    : max(60, min(86400, (int)(getenv('TYPO3_SOLR_SCHEDULER_INTERVAL') ?: 300)));
$siteIdentifier = getenv('TYPO3_SOLR_SITE_IDENTIFIER') ?: 'camino';
$rootPageId = getenv('TYPO3_SOLR_ROOT_PAGE_ID') ?: '1';
$slug = getenv('TYPO3_SOLR_SEARCH_SLUG') ?: '/search';
$title = getenv('TYPO3_SOLR_SEARCH_TITLE') ?: 'Search';

$command = [
    $root . '/vendor/bin/typo3',
    'webconsulting:solr-demo:setup',
    '--limit=' . $limit,
    '--site-identifier=' . $siteIdentifier,
    '--root-page-id=' . $rootPageId,
    '--slug=' . $slug,
    '--title=' . $title,
];

if ($action === 'setup') {
    $command[] = '--flush-caches';
    $command[] = '--normalize-demo-pages';
    if (typo3_solr_should_index_on_setup()) {
        $command[] = '--index';
    }
    if (typo3_solr_should_create_scheduler_task()) {
        $command[] = '--scheduler-task';
        $command[] = '--scheduler-interval=' . $schedulerInterval;
    }
}

if ($action === 'diagnose') {
    $command[] = '--diagnose';
}

$process = proc_open(
    $command,
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
    echo "Failed to start TYPO3 Solr demo setup.\n";
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
    echo $exitCode === 0 ? "TYPO3 Solr demo setup finished.\n" : "TYPO3 Solr demo setup failed.\n";
}

if ($exitCode === 0 && $action === 'setup' && !typo3_solr_should_index_on_setup()) {
    echo "Runtime indexing was skipped because the internal Vercel Solr demo service is in use.\n";
    echo "That service self-seeds the static Camino demo documents on startup. Set TYPO3_SOLR_INDEX_ON_SETUP=1 to force bounded runtime indexing.\n";
}

function typo3_solr_should_index_on_setup(): bool
{
    if (isset($_GET['index'])) {
        return typo3_solr_truthy((string)$_GET['index']);
    }

    $explicit = getenv('TYPO3_SOLR_INDEX_ON_SETUP');
    if ($explicit !== false && $explicit !== '') {
        return typo3_solr_truthy((string)$explicit);
    }

    $externalConnection = typo3_solr_env_present('TYPO3_SOLR_URL')
        || typo3_solr_env_present('SOLR_URL')
        || typo3_solr_env_present('TYPO3_SOLR_HOST')
        || typo3_solr_env_present('SOLR_HOST');

    if ($externalConnection) {
        return true;
    }

    return !typo3_solr_has_internal_service_url();
}

function typo3_solr_should_create_scheduler_task(): bool
{
    if (isset($_GET['scheduler'])) {
        return typo3_solr_truthy((string)$_GET['scheduler']);
    }

    $explicit = getenv('TYPO3_SOLR_SCHEDULER_TASK');
    if ($explicit !== false && $explicit !== '') {
        return typo3_solr_truthy((string)$explicit);
    }

    return typo3_solr_should_index_on_setup() && !typo3_solr_has_internal_service_url();
}

function typo3_solr_has_internal_service_url(): bool
{
    return typo3_solr_env_present('TYPO3_SOLR_SERVICE_URL')
        || typo3_solr_env_present('SOLR_SERVICE_URL')
        || typo3_solr_env_present('TYPO3_SOLR_INTERNAL_URL')
        || typo3_solr_env_present('SOLR_INTERNAL_URL');
}

function typo3_solr_truthy(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function typo3_solr_env_present(string $name): bool
{
    $value = getenv($name);
    return $value !== false && $value !== '';
}

function typo3_solr_probe(): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $target = isset($_GET['target']) ? strtolower((string)$_GET['target']) : 'all';
    $timeout = isset($_GET['timeout']) && is_numeric($_GET['timeout']) ? (float)$_GET['timeout'] : 3.0;
    $timeout = max(1.0, min(10.0, $timeout));

    $serviceUrl = getenv('TYPO3_SOLR_SERVICE_URL')
        ?: getenv('SOLR_SERVICE_URL')
        ?: getenv('TYPO3_SOLR_INTERNAL_URL')
        ?: getenv('SOLR_INTERNAL_URL')
        ?: getenv('TYPO3_SOLR_URL')
        ?: getenv('SOLR_URL');

    if ($serviceUrl === false || $serviceUrl === '') {
        http_response_code(503);
        echo "No Solr service URL is configured.\n";
        return;
    }

    $core = getenv('TYPO3_SOLR_CORE') ?: getenv('SOLR_CORE') ?: 'core_en';
    $base = rtrim((string)$serviceUrl, '/');
    $selectParams = [
        'q' => isset($_GET['q']) ? substr((string)$_GET['q'], 0, 200) : '*:*',
        'rows' => isset($_GET['rows']) && is_numeric($_GET['rows']) ? max(0, min(20, (int)$_GET['rows'])) : 0,
        'wt' => 'json',
    ];

    if (isset($_GET['fl']) && (string)$_GET['fl'] !== '') {
        $selectParams['fl'] = substr((string)$_GET['fl'], 0, 300);
    }

    if (isset($_GET['fq']) && (string)$_GET['fq'] !== '') {
        $selectParams['fq'] = substr((string)$_GET['fq'], 0, 300);
    }

    $requests = [
        'cores' => $base . '/solr/admin/cores?action=STATUS',
        'ping' => $base . '/solr/' . rawurlencode((string)$core) . '/admin/ping',
        'select' => $base . '/solr/' . rawurlencode((string)$core) . '/select?' . http_build_query($selectParams, '', '&', PHP_QUERY_RFC3986),
    ];

    if ($target !== 'all') {
        if (!isset($requests[$target])) {
            http_response_code(400);
            echo "Unsupported probe target. Use all, cores, ping, or select.\n";
            return;
        }
        $requests = [$target => $requests[$target]];
    }

    foreach ($requests as $label => $url) {
        $result = typo3_solr_probe_request($url, $timeout);
        echo sprintf("[%s] HTTP %s in %.3fs\n", $label, $result['status'], $result['time']);
        if ($result['error'] !== '') {
            echo "error: " . $result['error'] . "\n";
        }
        if ($result['body'] !== '') {
            echo typo3_solr_redact($result['body']) . "\n";
        }
        echo "\n";
        flush();
    }
}

/**
 * @return array{status:int|string,time:float,error:string,body:string}
 */
function typo3_solr_probe_request(string $url, float $timeout): array
{
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $started = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $time = microtime(true) - $started;
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $status = 'n/a';

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
            $status = (int)$match[1];
            break;
        }
    }

    $error = '';
    if ($body === false) {
        $lastError = error_get_last();
        $error = is_array($lastError) ? (string)($lastError['message'] ?? '') : 'request failed';
        $body = '';
    }

    return [
        'status' => $status,
        'time' => $time,
        'error' => $error,
        'body' => substr((string)$body, 0, 3000),
    ];
}

function typo3_solr_logs(string $root): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $paths = [
        $root . '/var/log',
        '/tmp/typo3/var/log',
        '/tmp/typo3-log',
        '/tmp/typo3',
    ];

    $files = [];
    foreach ($paths as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $matches = glob(rtrim($path, '/') . '/*.{log,json,txt}', GLOB_BRACE);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $file) {
            if (is_file($file) && is_readable($file)) {
                $files[$file] = filemtime($file) ?: 0;
            }
        }
    }

    if ($files === []) {
        echo "No readable TYPO3 log files found in known runtime paths.\n";
        return;
    }

    arsort($files);
    $files = array_slice($files, 0, 8, true);

    foreach ($files as $file => $mtime) {
        echo sprintf("--- %s (%s)\n", $file, date(DATE_ATOM, (int)$mtime));
        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            echo "[empty]\n\n";
            continue;
        }
        $tail = substr($content, -6000);
        echo typo3_solr_redact($tail) . "\n\n";
    }
}

function typo3_solr_runtime(string $root): void
{
    header('Content-Type: text/plain; charset=utf-8');

    echo "TYPO3 Vercel runtime diagnostics\n";
    echo "root=" . $root . "\n";
    echo "php_sapi=" . PHP_SAPI . "\n";
    echo "uid=" . (function_exists('posix_geteuid') ? (string)posix_geteuid() : 'n/a') . "\n";
    echo "VERCEL=" . (getenv('VERCEL') === false ? 'missing' : 'present') . "\n";
    echo "VERCEL_URL=" . (getenv('VERCEL_URL') === false ? 'missing' : 'present') . "\n";
    echo "TYPO3_CACHE_BACKEND=" . typo3_solr_public_env_value('TYPO3_CACHE_BACKEND') . "\n";
    echo "TYPO3_RUNTIME_LOCK_DIR=" . typo3_solr_public_env_value('TYPO3_RUNTIME_LOCK_DIR') . "\n\n";

    foreach (
        [
            'project_var' => $root . '/var',
            'project_var_lock' => $root . '/var/lock',
            'tmp_root' => '/tmp/typo3',
            'tmp_var' => '/tmp/typo3/var',
            'tmp_var_lock' => '/tmp/typo3/var/lock',
            'tmp_var_cache' => '/tmp/typo3/var/cache',
            'tmp_var_log' => '/tmp/typo3/var/log',
            'tmp_uploads' => '/tmp/typo3/fileadmin',
            'tmp_public_temp' => '/tmp/typo3/typo3temp',
            'tmp_php' => '/tmp/typo3/tmp',
            'tmp_gfx' => '/tmp/typo3/gm',
            'tmp_sessions' => '/tmp/typo3/php-sessions',
            'public_fileadmin' => $root . '/public/fileadmin',
            'public_typo3temp' => $root . '/public/typo3temp',
        ] as $label => $path
    ) {
        typo3_solr_runtime_path_line($label, $path);
    }
}

function typo3_solr_runtime_path_line(string $label, string $path): void
{
    $exists = file_exists($path);
    $isLink = is_link($path);
    $target = $isLink ? (readlink($path) ?: 'unreadable') : '-';
    $mode = $exists ? substr(sprintf('%o', (int)fileperms($path)), -4) : '----';

    echo sprintf(
        "%s path=%s exists=%s dir=%s link=%s target=%s writable=%s mode=%s\n",
        $label,
        $path,
        $exists ? 'yes' : 'no',
        is_dir($path) ? 'yes' : 'no',
        $isLink ? 'yes' : 'no',
        $target,
        is_writable($path) ? 'yes' : 'no',
        $mode,
    );
}

function typo3_solr_public_env_value(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return 'missing';
    }

    return preg_match('/(PASSWORD|SECRET|TOKEN|KEY|URL|DSN)/i', $name) === 1 ? 'present' : $value;
}

function typo3_solr_redact(string $value): string
{
    $value = preg_replace('/(password|token|api[_-]?key|secret)=([^&\s]+)/i', '$1=[redacted]', $value) ?? $value;
    $value = preg_replace('/(Authorization:\s*Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[redacted]', $value) ?? $value;
    $value = preg_replace('/https?:\/\/([^:@\s]+):([^@\s]+)@/i', 'https://[redacted]@', $value) ?? $value;
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim(preg_replace('/[[:space:]]+/', ' ', $value) ?? $value);
}
