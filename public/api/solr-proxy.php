<?php

declare(strict_types=1);

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$publicProxy = typo3_solr_proxy_truthy(getenv('TYPO3_SOLR_APP_PROXY_PUBLIC') ?: '');
$loopbackAddress = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
$loopbackHost = preg_match('/^(127\.0\.0\.1|localhost)(:[0-9]+)?$/', $host) === 1;

if (!$publicProxy && (!$loopbackAddress || !$loopbackHost)) {
    http_response_code(404);
    echo "Not found.\n";
    exit;
}

$serviceUrl = getenv('TYPO3_SOLR_SERVICE_URL')
    ?: getenv('SOLR_SERVICE_URL')
    ?: getenv('TYPO3_SOLR_INTERNAL_URL')
    ?: getenv('SOLR_INTERNAL_URL');

if (!is_string($serviceUrl) || $serviceUrl === '') {
    http_response_code(503);
    echo "No internal Solr service URL is configured.\n";
    exit;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/api/solr-proxy/';
$targetUrl = typo3_solr_proxy_target_url($serviceUrl, $requestUri);
$timeout = typo3_solr_proxy_float_env('TYPO3_SOLR_APP_PROXY_REQUEST_TIMEOUT', 4.0, 1.0, 15.0);
$startedAt = microtime(true);
$deadline = $startedAt + typo3_solr_proxy_float_env('TYPO3_SOLR_APP_PROXY_TOTAL_TIMEOUT', 20.0, 1.0, 60.0);
$retryDelay = typo3_solr_proxy_float_env('TYPO3_SOLR_APP_PROXY_RETRY_DELAY', 0.25, 0.05, 2.0);
$body = file_get_contents('php://input');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$headers = typo3_solr_proxy_request_headers($targetUrl);
$last = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'not requested'];
$attempts = 0;
$connections = 0;
$handle = typo3_solr_proxy_create_handle($targetUrl, $method, $headers, $body === false ? '' : $body);

if ($handle !== null) {
    do {
        $attempts++;
        $remaining = max(0.001, $deadline - microtime(true));
        $last = typo3_solr_proxy_request($handle, min($timeout, $remaining));
        $connections += (int)curl_getinfo($handle, CURLINFO_NUM_CONNECTS);
        if (!typo3_solr_proxy_should_retry($last)) {
            break;
        }

        $remainingMicroseconds = (int)(($deadline - microtime(true)) * 1_000_000);
        if ($remainingMicroseconds <= 0) {
            break;
        }
        usleep(min($remainingMicroseconds, (int)($retryDelay * 1_000_000)));
    } while (microtime(true) < $deadline);

    curl_close($handle);
}

error_log(json_encode([
    'level' => $last['status'] >= 500 || $last['status'] === 0 ? 'warning' : 'info',
    'component' => 'solr-proxy',
    'event' => 'upstream-request',
    'path' => parse_url($targetUrl, PHP_URL_PATH),
    'status' => $last['status'],
    'attempts' => $attempts,
    'connections' => $connections,
    'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

if ($last['status'] === 0) {
    http_response_code(503);
    header('Retry-After: 2');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Internal Solr service is unavailable: " . $last['error'] . "\n";
    exit;
}

http_response_code($last['status']);
foreach ($last['headers'] as $name => $value) {
    header($name . ': ' . $value, false);
}

if ($method !== 'HEAD') {
    echo $last['body'];
}

function typo3_solr_proxy_target_url(string $serviceUrl, string $requestUri): string
{
    $parts = parse_url($requestUri);
    $path = (string)($parts['path'] ?? '/api/solr-proxy/');
    $prefixes = ['/api/solr-proxy.php', '/api/solr-proxy'];
    $suffix = $path;
    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $suffix = substr($path, strlen($prefix));
            break;
        }
    }
    $suffix = $suffix === '' ? '/' : $suffix;

    $target = rtrim($serviceUrl, '/') . '/' . ltrim($suffix, '/');
    if (isset($parts['query']) && $parts['query'] !== '') {
        $target .= '?' . $parts['query'];
    }

    return $target;
}

/**
 * @return array<string, string>
 */
function typo3_solr_proxy_request_headers(string $targetUrl): array
{
    $skip = [
        'authorization' => true,
        'connection' => true,
        'content-length' => true,
        'host' => true,
        'keep-alive' => true,
        'proxy-authenticate' => true,
        'proxy-authorization' => true,
        'te' => true,
        'trailer' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
    ];
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        if (isset($skip[strtolower($name)])) {
            continue;
        }
        $headers[$name] = (string)$value;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($contentType !== '') {
        $headers['Content-Type'] = (string)$contentType;
    }

    $targetHost = parse_url($targetUrl, PHP_URL_HOST);
    if (is_string($targetHost) && $targetHost !== '') {
        $headers['Host'] = $targetHost;
    }
    return $headers;
}

/**
 * @param array<string, string> $headers
 * @return CurlHandle|null
 */
function typo3_solr_proxy_create_handle(string $url, string $method, array $headers, string $body): ?CurlHandle
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    if ($method === 'HEAD') {
        curl_setopt($handle, CURLOPT_NOBODY, true);
    }

    return $handle;
}

/**
 * @return array{status:int,headers:array<string,string>,body:string,error:string}
 */
function typo3_solr_proxy_request(CurlHandle $handle, float $timeout): array
{
    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_CONNECTTIMEOUT_MS => min(2000, max(1, (int)($timeout * 1000))),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            unset($handle);
            $length = strlen($line);
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'HTTP/')) {
                return $length;
            }

            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                return $length;
            }

            $lower = strtolower($name);
            if (in_array($lower, ['connection', 'content-length', 'transfer-encoding'], true)) {
                return $length;
            }

            $responseHeaders[$name] = trim($value);
            return $length;
        },
        CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
    ]);

    $responseBody = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error,
    ];
}

/**
 * @param array{status:int,headers:array<string,string>,body:string,error:string} $response
 */
function typo3_solr_proxy_should_retry(array $response): bool
{
    if (in_array($response['status'], [0, 502, 503, 504], true)) {
        return true;
    }

    return $response['status'] === 500 && str_contains(strtolower($response['body']), 'starting');
}

function typo3_solr_proxy_float_env(string $name, float $default, float $min, float $max): float
{
    $value = getenv($name);
    if ($value === false || $value === '' || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (float)$value));
}

function typo3_solr_proxy_truthy(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}
