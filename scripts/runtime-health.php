<?php

declare(strict_types=1);

use Webconsulting\Typo3VercelBlobStorage\Authentication\BlobCredentials;
use Webconsulting\Typo3VercelBlobStorage\Client\VercelBlobClient;

function typo3_vercel_health_authorized(): bool
{
    $secret = getenv('CRON_SECRET');
    if (!is_string($secret) || $secret === '') {
        return false;
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $authorization = (string)$value;
                break;
            }
        }
    }

    return hash_equals('Bearer ' . $secret, (string)$authorization);
}

/**
 * @param callable(): array<string, mixed> $check
 * @return array<string, mixed>
 */
function typo3_vercel_health_measure(callable $check): array
{
    $startedAt = microtime(true);
    try {
        $result = $check();
        return [
            'status' => 'ok',
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ...$result,
        ];
    } catch (Throwable $exception) {
        return [
            'status' => 'error',
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'error' => mb_substr($exception->getMessage(), 0, 300),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function typo3_vercel_health_database(): array
{
    return typo3_vercel_health_measure(static function (): array {
        $database = typo3_vercel_database_config();
        $pdo = new PDO(
            typo3_vercel_pdo_dsn($database),
            (string)($database['user'] ?? ''),
            (string)($database['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
        $statement = $pdo->query('SELECT 1');
        if ($statement === false || $statement->fetchColumn() === false) {
            throw new RuntimeException('Database probe did not return a row.');
        }

        return ['driver' => (string)($database['driver'] ?? 'unknown')];
    });
}

/**
 * @return array<string, mixed>
 */
function typo3_vercel_health_redis(): array
{
    if (typo3_vercel_cache_backend() !== 'redis') {
        return ['status' => 'skipped', 'reason' => 'Redis cache is not enabled.'];
    }

    return typo3_vercel_health_measure(static function (): array {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('The PHP Redis extension is not loaded.');
        }
        $options = typo3_vercel_redis_cache_base_options();
        if ($options === null) {
            throw new RuntimeException('No Redis TCP/TLS connection is configured.');
        }

        $redis = new Redis();
        $redis->connect(
            (string)$options['hostname'],
            (int)$options['port'],
            (float)$options['connectionTimeout'],
        );
        if (isset($options['password'])) {
            $credentials = isset($options['username'])
                ? [(string)$options['username'], (string)$options['password']]
                : (string)$options['password'];
            if (!$redis->auth($credentials)) {
                throw new RuntimeException('Redis authentication failed.');
            }
        }
        if ((int)($options['database'] ?? 0) !== 0) {
            $redis->select((int)$options['database']);
        }
        $pong = $redis->ping();
        $redis->close();
        if ($pong !== true && !str_contains(strtoupper((string)$pong), 'PONG')) {
            throw new RuntimeException('Redis ping failed.');
        }

        return ['transport' => str_starts_with((string)$options['hostname'], 'tls://') ? 'tls' : 'tcp'];
    });
}

/**
 * @return array<string, mixed>
 */
function typo3_vercel_health_blob(bool $writeProbe = false): array
{
    $driver = strtolower((string)(getenv('TYPO3_OBJECT_STORAGE_DRIVER') ?: ''));
    $enabled = typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_ENABLED', false)
        || in_array($driver, ['vercel_blob', 'blob', 'vercel-blob'], true)
        || getenv('BLOB_STORE_ID') !== false
        || getenv('BLOB_READ_WRITE_TOKEN') !== false;
    if (!$enabled || in_array($driver, ['vercel_s3', 's3', 's3-compatible'], true)) {
        return ['status' => 'skipped', 'reason' => 'Vercel Blob is not enabled.'];
    }

    return typo3_vercel_health_measure(static function () use ($writeProbe): array {
        $tokenEnvName = (string)(getenv('TYPO3_BLOB_TOKEN_ENV_NAME') ?: 'BLOB_READ_WRITE_TOKEN');
        $storeId = BlobCredentials::resolveStoreId(getenv('TYPO3_BLOB_STORE_ID'), $tokenEnvName);
        if ($storeId === null) {
            throw new RuntimeException('Blob store id is unavailable.');
        }
        $token = BlobCredentials::resolveToken(null, $tokenEnvName, true);
        if ($token === null) {
            throw new RuntimeException('Blob authentication token is unavailable.');
        }

        $access = strtolower((string)(getenv('TYPO3_BLOB_ACCESS') ?: 'public')) === 'private' ? 'private' : 'public';
        $prefix = trim((string)(getenv('TYPO3_BLOB_PREFIX') ?: 'typo3/'), '/') . '/';
        $apiUrl = (string)(getenv('TYPO3_BLOB_API_URL') ?: getenv('VERCEL_BLOB_API_URL') ?: 'https://vercel.com/api/blob');
        $client = new VercelBlobClient($storeId, $access, $token, $apiUrl, 1, 10);
        $client->listPathnames($prefix, 1);

        if ($writeProbe) {
            $pathname = $prefix . '_health/' . bin2hex(random_bytes(8)) . '.txt';
            $payload = 'typo3-vercel-blob-health-' . bin2hex(random_bytes(8));
            try {
                $client->put($pathname, $payload, 'text/plain; charset=utf-8', 60);
                if (!hash_equals($payload, $client->getContents($pathname))) {
                    throw new RuntimeException('Blob write probe returned different content.');
                }
            } finally {
                $client->delete([$pathname]);
            }
        }

        return [
            'access' => $access,
            'authentication' => BlobCredentials::requestOidcToken() !== null ? 'oidc' : 'read-write-token',
            'write_probe' => $writeProbe ? 'passed' : 'not-requested',
        ];
    });
}

/**
 * @return array<string, mixed>
 */
function typo3_vercel_health_solr(float $timeout = 20.0): array
{
    $serviceUrl = getenv('TYPO3_SOLR_SERVICE_URL')
        ?: getenv('SOLR_SERVICE_URL')
        ?: getenv('TYPO3_SOLR_INTERNAL_URL')
        ?: getenv('SOLR_INTERNAL_URL');
    $core = (string)(getenv('TYPO3_SOLR_CORE') ?: getenv('SOLR_CORE') ?: 'core_en');

    if (is_string($serviceUrl) && $serviceUrl !== '') {
        $url = rtrim($serviceUrl, '/') . '/solr/' . rawurlencode($core) . '/admin/ping?wt=json';
    } else {
        $externalUrl = getenv('TYPO3_SOLR_URL') ?: getenv('SOLR_URL');
        if (!is_string($externalUrl) || $externalUrl === '') {
            return ['status' => 'skipped', 'reason' => 'Solr is not configured.'];
        }
        $url = rtrim($externalUrl, '/') . '/admin/ping?wt=json';
    }

    return typo3_vercel_health_http_with_retry($url, $timeout);
}

/**
 * @return array<string, mixed>
 */
function typo3_vercel_health_typo3_loopback(float $timeout = 20.0, string $path = '/'): array
{
    $port = max(1, (int)(getenv('PORT') ?: 80));
    $host = (string)(getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('#^https?://#', '', $host) ?? $host;
    $host = explode('/', $host, 2)[0];

    $path = '/' . ltrim($path, '/');

    return typo3_vercel_health_http('http://127.0.0.1:' . $port . $path, $timeout, [
        'Host: ' . $host,
        'X-Forwarded-Proto: https',
    ]);
}

/**
 * @param list<string> $headers
 * @return array<string, mixed>
 */
function typo3_vercel_health_http(string $url, float $timeout, array $headers = []): array
{
    return typo3_vercel_health_measure(static function () use ($url, $timeout, $headers): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, (int)($timeout * 1000)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if ($body === false || $status < 200 || $status >= 400) {
            throw new RuntimeException(sprintf('HTTP probe failed with status %d%s.', $status, $error !== '' ? ': ' . $error : ''));
        }

        return ['http_status' => $status];
    });
}

/**
 * Retry temporary service-start responses while keeping one total timeout.
 *
 * @param list<string> $headers
 * @return array<string, mixed>
 */
function typo3_vercel_health_http_with_retry(string $url, float $timeout, array $headers = []): array
{
    return typo3_vercel_health_measure(static function () use ($url, $timeout, $headers): array {
        $deadline = microtime(true) + max(0.1, $timeout);
        $attempts = 0;
        $connections = 0;
        $lastStatus = 0;
        $lastError = '';
        $successful = false;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        do {
            ++$attempts;
            $remainingMilliseconds = max(1, (int)(($deadline - microtime(true)) * 1000));
            $attemptMilliseconds = min(4000, $remainingMilliseconds);
            curl_setopt_array($handle, [
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $attemptMilliseconds),
                CURLOPT_TIMEOUT_MS => $attemptMilliseconds,
            ]);
            $body = curl_exec($handle);
            $lastStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($handle);
            $connections += (int)curl_getinfo($handle, CURLINFO_NUM_CONNECTS);

            if ($body !== false && $lastStatus >= 200 && $lastStatus < 400) {
                $successful = true;
                break;
            }

            if (!typo3_vercel_health_http_status_is_temporary($lastStatus)) {
                break;
            }

            $remainingMicroseconds = (int)(($deadline - microtime(true)) * 1_000_000);
            if ($remainingMicroseconds <= 0) {
                break;
            }
            $backoffMicroseconds = min(1_000_000, 250_000 * $attempts);
            usleep(min($remainingMicroseconds, $backoffMicroseconds));
        } while (microtime(true) < $deadline);


        if ($successful) {
            return [
                'http_status' => $lastStatus,
                'attempts' => $attempts,
                'connections' => $connections,
            ];
        }

        throw new RuntimeException(sprintf(
            'HTTP probe failed after %d attempt(s) with status %d%s.',
            $attempts,
            $lastStatus,
            $lastError !== '' ? ': ' . $lastError : '',
        ));
    });
}

function typo3_vercel_health_http_status_is_temporary(int $status): bool
{
    return in_array($status, [0, 500, 502, 503, 504], true);
}

/**
 * @param array<string, mixed> $checks
 */
function typo3_vercel_health_checks_ok(array $checks): bool
{
    foreach ($checks as $check) {
        if (is_array($check) && ($check['status'] ?? null) === 'error') {
            return false;
        }
    }
    return true;
}
