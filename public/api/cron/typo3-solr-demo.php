<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';
typo3_vercel_export_request_oidc_token();

typo3_vercel_require_cron_secret();

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

if ($action === 'benchmark') {
    typo3_solr_benchmark();
    exit;
}

if (!in_array($action, ['setup', 'diagnose'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unsupported action. Use setup, diagnose, probe, benchmark, logs, or runtime.\n";
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

$arguments = [
    'webconsulting:solr-demo:setup',
    '--limit=' . $limit,
    '--site-identifier=' . $siteIdentifier,
    '--root-page-id=' . $rootPageId,
    '--slug=' . $slug,
    '--title=' . $title,
];

if ($action === 'setup') {
    $arguments[] = '--flush-caches';
    $arguments[] = '--normalize-demo-pages';
    if (typo3_solr_should_index_on_setup()) {
        $arguments[] = '--index';
    }
    if (typo3_solr_should_create_scheduler_task()) {
        $arguments[] = '--scheduler-task';
        $arguments[] = '--scheduler-interval=' . $schedulerInterval;
    }
}

if ($action === 'diagnose') {
    $arguments[] = '--diagnose';
}

try {
    ['output' => $output, 'exitCode' => $exitCode] = typo3_vercel_run_typo3_command($arguments);
} catch (RuntimeException) {
    http_response_code(500);
    echo "Failed to start TYPO3 Solr demo setup.\n";
    exit;
}

http_response_code($exitCode === 0 ? 200 : 500);
header('Content-Type: text/plain; charset=utf-8');

if ($output !== '') {
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
        return typo3_vercel_truthy((string)$_GET['index']);
    }

    $explicit = typo3_vercel_env('TYPO3_SOLR_INDEX_ON_SETUP');
    if ($explicit !== null) {
        return typo3_vercel_truthy($explicit);
    }

    $externalConnection = typo3_vercel_env('TYPO3_SOLR_URL') !== null
        || typo3_vercel_env('SOLR_URL') !== null
        || typo3_vercel_env('TYPO3_SOLR_HOST') !== null
        || typo3_vercel_env('SOLR_HOST') !== null;

    if ($externalConnection) {
        return true;
    }

    return typo3_vercel_solr_service_url() === null;
}

function typo3_solr_should_create_scheduler_task(): bool
{
    if (isset($_GET['scheduler'])) {
        return typo3_vercel_truthy((string)$_GET['scheduler']);
    }

    $explicit = typo3_vercel_env('TYPO3_SOLR_SCHEDULER_TASK');
    if ($explicit !== null) {
        return typo3_vercel_truthy($explicit);
    }

    return typo3_solr_should_index_on_setup() && typo3_vercel_solr_service_url() === null;
}

function typo3_solr_probe(): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $target = isset($_GET['target']) ? strtolower((string)$_GET['target']) : 'all';
    $usesAppProxy = typo3_vercel_solr_service_url() !== null && typo3_solr_app_proxy_enabled();
    $defaultTimeout = $usesAppProxy ? 45.0 : 3.0;
    $maxTimeout = $usesAppProxy ? 90.0 : 10.0;
    $timeout = isset($_GET['timeout']) && is_numeric($_GET['timeout']) ? (float)$_GET['timeout'] : $defaultTimeout;
    $timeout = max(1.0, min($maxTimeout, $timeout));

    $serviceUrl = typo3_vercel_solr_service_url()
        ?? typo3_vercel_env('TYPO3_SOLR_URL')
        ?? typo3_vercel_env('SOLR_URL');

    if ($serviceUrl === null) {
        http_response_code(503);
        echo "No Solr service URL is configured.\n";
        return;
    }

    $core = getenv('TYPO3_SOLR_CORE') ?: getenv('SOLR_CORE') ?: 'core_en';
    $base = rtrim(typo3_solr_probe_base_url((string)$serviceUrl), '/');
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

function typo3_solr_probe_base_url(string $serviceUrl): string
{
    if (typo3_vercel_solr_service_url() !== null && typo3_solr_app_proxy_enabled()) {
        $port = getenv('TYPO3_SOLR_APP_PROXY_PORT') ?: getenv('PORT') ?: '80';
        return 'http://127.0.0.1:' . (int)$port . '/api/solr-proxy.php';
    }

    return $serviceUrl;
}

function typo3_solr_app_proxy_enabled(): bool
{
    $value = typo3_vercel_env('TYPO3_SOLR_APP_PROXY_ENABLED');

    return $value === null || typo3_vercel_truthy($value);
}

function typo3_solr_benchmark(): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $serviceUrl = typo3_vercel_solr_service_url()
        ?? typo3_vercel_env('TYPO3_SOLR_URL')
        ?? typo3_vercel_env('SOLR_URL');

    if ($serviceUrl === null) {
        http_response_code(503);
        echo "No Solr service URL is configured.\n";
        return;
    }

    $core = getenv('TYPO3_SOLR_CORE') ?: getenv('SOLR_CORE') ?: 'core_en';
    $base = rtrim(typo3_solr_probe_base_url((string)$serviceUrl), '/');
    $documents = typo3_solr_int_query('documents', 20, 1, 100);
    $updateRuns = typo3_solr_int_query('updateRuns', 5, 1, 20);
    $searchRuns = typo3_solr_int_query('searchRuns', 10, 1, 50);
    $timeout = typo3_solr_float_query('timeout', 60.0, 1.0, 90.0);
    $benchmarkHash = 'vercel-benchmark';
    $runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $changed = gmdate('Y-m-d\TH:i:s\Z');

    $updateUrl = $base . '/solr/' . rawurlencode((string)$core) . '/update?commit=true';
    $docsUrl = $base . '/solr/' . rawurlencode((string)$core) . '/update/json/docs?commit=true';
    $selectUrl = $base . '/solr/' . rawurlencode((string)$core) . '/select';
    $benchmarkQuery = 'siteHash:' . $benchmarkHash;

    echo "TYPO3 Solr benchmark\n";
    echo "core=" . $core . "\n";
    echo "documents=" . $documents . " updateRuns=" . $updateRuns . " searchRuns=" . $searchRuns . "\n";
    echo "runId=" . $runId . "\n\n";

    $deleteBefore = typo3_solr_benchmark_request(
        $updateUrl,
        'POST',
        json_encode(['delete' => ['query' => $benchmarkQuery]], JSON_THROW_ON_ERROR),
        $timeout,
    );
    typo3_solr_benchmark_print_request('cleanup_before', $deleteBefore);

    $benchmarkDocs = [];
    for ($i = 1; $i <= $documents; $i++) {
        $benchmarkDocs[] = typo3_solr_benchmark_document($benchmarkHash, $runId, $i, $changed);
    }

    $index = typo3_solr_benchmark_request(
        $docsUrl,
        'POST',
        json_encode($benchmarkDocs, JSON_THROW_ON_ERROR),
        $timeout,
    );
    typo3_solr_benchmark_print_request('index_add_commit', $index);

    $countAfterIndex = typo3_solr_benchmark_select_count($selectUrl, $benchmarkHash, $timeout);
    echo sprintf(
        "index_count_check http=%s seconds=%.3f numFound=%s\n\n",
        (string)$countAfterIndex['status'],
        $countAfterIndex['time'],
        (string)($countAfterIndex['count'] ?? 'n/a'),
    );

    $updateTimes = [];
    for ($i = 1; $i <= $updateRuns; $i++) {
        $doc = typo3_solr_benchmark_document($benchmarkHash, $runId, 1, gmdate('Y-m-d\TH:i:s\Z'));
        $doc['content'] = 'Updated TYPO3 Vercel Solr benchmark document run ' . $runId . ' update ' . $i . '.';
        $update = typo3_solr_benchmark_request(
            $docsUrl,
            'POST',
            json_encode($doc, JSON_THROW_ON_ERROR),
            $timeout,
        );
        $updateTimes[] = $update['time'];
        typo3_solr_benchmark_print_request('update_' . $i, $update);
    }
    typo3_solr_benchmark_print_stats('update_commit_seconds', $updateTimes);

    $searchTimes = [];
    $lastFound = null;
    for ($i = 1; $i <= $searchRuns; $i++) {
        $queryUrl = $selectUrl . '?' . http_build_query([
            'q' => 'benchmark',
            'fq' => 'siteHash:"' . $benchmarkHash . '"',
            'rows' => 10,
            'fl' => 'id,title,score',
            'wt' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);
        $search = typo3_solr_benchmark_request($queryUrl, 'GET', null, $timeout);
        $searchTimes[] = $search['time'];
        $decoded = json_decode($search['body'], true);
        if (is_array($decoded)) {
            $lastFound = $decoded['response']['numFound'] ?? $lastFound;
        }
        typo3_solr_benchmark_print_request('search_' . $i, $search);
    }
    echo "search_numFound=" . ($lastFound ?? 'n/a') . "\n";
    typo3_solr_benchmark_print_stats('search_seconds', $searchTimes);

    $deleteAfter = typo3_solr_benchmark_request(
        $updateUrl,
        'POST',
        json_encode(['delete' => ['query' => $benchmarkQuery]], JSON_THROW_ON_ERROR),
        $timeout,
    );
    typo3_solr_benchmark_print_request('cleanup_after', $deleteAfter);

    echo "benchmark_finished=yes\n";
}

function typo3_solr_int_query(string $name, int $default, int $min, int $max): int
{
    $value = $_GET[$name] ?? null;
    if (!is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int)$value));
}

function typo3_solr_float_query(string $name, float $default, float $min, float $max): float
{
    $value = $_GET[$name] ?? null;
    if (!is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (float)$value));
}

/**
 * @return array<string, mixed>
 */
function typo3_solr_benchmark_document(string $siteHash, string $runId, int $uid, string $changed): array
{
    return [
        'id' => 'vercel-benchmark/pages/' . $runId . '/' . $uid . '/0/0/c:0',
        'site' => 'benchmark',
        'typo3Context_stringS' => 'Production',
        'siteHash' => $siteHash,
        'domain_stringS' => 'https://typo3-camino-vercel.vercel.app',
        'appKey' => 'EXT:solr',
        'type' => 'pages',
        'uid' => 900000 + $uid,
        'pid' => 1,
        'variantId' => 'vercel-benchmark/pages/' . $runId . '/' . $uid . '/0/0/c:0',
        'typeNum' => 0,
        'created' => '2026-01-01T00:00:00Z',
        'changed' => $changed,
        'rootline' => ['1', '900000'],
        'access' => ['c:0'],
        'title' => 'TYPO3 Vercel Solr Benchmark ' . $uid,
        'navTitle' => 'Benchmark ' . $uid,
        'content' => 'TYPO3 Vercel Solr benchmark document for measuring indexing update and search latency.',
        'url' => 'https://typo3-camino-vercel.vercel.app/search?benchmark=' . rawurlencode($runId) . '-' . $uid,
        'keywords' => ['benchmark', 'typo3', 'vercel', 'solr'],
    ];
}

/**
 * @return array{status:int|string,time:float,error:string,body:string}
 */
function typo3_solr_benchmark_request(string $url, string $method, ?string $body, float $timeout): array
{
    $headers = ['Connection: close'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $started = microtime(true);
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'time' => 0.0, 'error' => 'curl_init failed', 'body' => ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        return [
            'status' => $status,
            'time' => microtime(true) - $started,
            'error' => $error,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
            'timeout' => $timeout,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 'n/a';
    foreach (http_get_last_response_headers() ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
            $status = (int)$match[1];
            break;
        }
    }
    $lastError = $responseBody === false ? error_get_last() : null;

    return [
        'status' => $status,
        'time' => microtime(true) - $started,
        'error' => is_array($lastError) ? (string)$lastError['message'] : '',
        'body' => is_string($responseBody) ? $responseBody : '',
    ];
}

/**
 * @return array{count:int|null,time:float,status:int|string}
 */
function typo3_solr_benchmark_select_count(string $selectUrl, string $siteHash, float $timeout): array
{
    $url = $selectUrl . '?' . http_build_query([
        'q' => '*:*',
        'fq' => 'siteHash:"' . $siteHash . '"',
        'rows' => 0,
        'wt' => 'json',
    ], '', '&', PHP_QUERY_RFC3986);
    $result = typo3_solr_benchmark_request($url, 'GET', null, $timeout);
    $decoded = json_decode($result['body'], true);

    return [
        'count' => is_array($decoded) ? (int)($decoded['response']['numFound'] ?? 0) : null,
        'time' => $result['time'],
        'status' => $result['status'],
    ];
}

/**
 * @param array{status:int|string,time:float,error:string,body:string} $result
 */
function typo3_solr_benchmark_print_request(string $label, array $result): void
{
    echo sprintf(
        "%s http=%s seconds=%.3f%s\n",
        $label,
        (string)$result['status'],
        $result['time'],
        $result['error'] !== '' ? ' error=' . typo3_solr_redact($result['error']) : '',
    );
    flush();
}

/**
 * @param float[] $values
 */
function typo3_solr_benchmark_print_stats(string $label, array $values): void
{
    sort($values);
    $count = count($values);
    if ($count === 0) {
        echo $label . " count=0\n\n";
        return;
    }

    $sum = array_sum($values);
    $median = $values[(int)floor(($count - 1) / 2)];
    $p95 = $values[(int)min($count - 1, ceil($count * 0.95) - 1)];
    echo sprintf(
        "%s count=%d min=%.3f median=%.3f mean=%.3f p95=%.3f max=%.3f\n\n",
        $label,
        $count,
        min($values),
        $median,
        $sum / $count,
        $p95,
        max($values),
    );
    flush();
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
    $headers = http_get_last_response_headers() ?? [];
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
        $error = is_array($lastError) ? (string)$lastError['message'] : 'request failed';
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
