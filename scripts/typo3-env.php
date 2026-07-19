<?php

declare(strict_types=1);

function typo3_vercel_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function typo3_vercel_bool_env(string $name, bool $default): bool
{
    $value = typo3_vercel_env($name);
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function typo3_vercel_truthy(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @param list<string> $names
 */
function typo3_vercel_first_env(array $names, string $default): string
{
    foreach ($names as $name) {
        $value = typo3_vercel_env($name);
        if ($value !== null) {
            return $value;
        }
    }
    return $default;
}

function typo3_vercel_int_env(string $name, int $default, int $min, int $max): int
{
    $value = typo3_vercel_env($name);
    if ($value === null || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int)$value));
}

function typo3_vercel_install_tool_direct_access(array $query): bool
{
    if (!array_key_exists('__typo3_install', $query)) {
        return false;
    }

    // TYPO3's authenticated System modules initialize a short-lived Install
    // session before redirecting to this context. The Install application
    // validates that session; standalone public access remains opt-in below.
    if (($query['install']['context'] ?? null) === 'backend') {
        return true;
    }

    return typo3_vercel_bool_env('TYPO3_INSTALL_TOOL_ENABLED', false);
}

/** @return list<int> */
function typo3_vercel_system_maintainers(): array
{
    $value = typo3_vercel_env('TYPO3_SYSTEM_MAINTAINERS');
    if ($value === null) {
        return [1];
    }

    $maintainers = [];
    foreach (explode(',', $value) as $item) {
        $item = trim($item);
        if (!preg_match('/^[1-9][0-9]*$/', $item)) {
            throw new RuntimeException(
                'TYPO3_SYSTEM_MAINTAINERS must contain comma-separated positive backend user UIDs.',
                1784120401,
            );
        }
        $maintainers[] = (int)$item;
    }

    return array_values(array_unique($maintainers));
}

/** @return list<int> */
function typo3_vercel_resolve_system_maintainers(array $database, string $username): array
{
    $pdo = typo3_vercel_pdo($database);
    $statement = $pdo->prepare(
        'SELECT uid
           FROM be_users
          WHERE username = :username
            AND admin = 1
            AND disable = 0
            AND deleted = 0
          ORDER BY uid',
    );
    $statement->execute(['username' => $username]);

    return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
}

function typo3_vercel_project_root(): string
{
    return str_replace('\\', '/', dirname(__DIR__));
}

function typo3_vercel_project_relative_path(string $absolutePath): string
{
    $target = str_replace('\\', '/', $absolutePath);
    if ($target === '' || $target[0] !== '/') {
        return trim($target, '/');
    }

    $fromParts = explode('/', trim(typo3_vercel_project_root(), '/'));
    $toParts = explode('/', trim($target, '/'));

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    return implode('/', array_merge(array_fill(0, count($fromParts), '..'), $toParts));
}

function typo3_vercel_is_vercel_runtime(): bool
{
    return typo3_vercel_env('VERCEL') === '1'
        || typo3_vercel_env('VERCEL_URL') !== null;
}

function typo3_vercel_export_request_oidc_token(): bool
{
    foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
        $value = $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $value = trim($value);
        putenv('VERCEL_OIDC_TOKEN=' . $value);
        $_ENV['VERCEL_OIDC_TOKEN'] = $value;
        return true;
    }

    return false;
}

function typo3_vercel_authorization_header(): string
{
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                return (string)$value;
            }
        }
    }

    return $authorization;
}

/**
 * Gate a maintenance/cron endpoint on the CRON_SECRET Bearer token, ending
 * the request on failure.
 */
function typo3_vercel_require_cron_secret(): void
{
    $secret = getenv('CRON_SECRET');
    if (!is_string($secret) || $secret === '') {
        http_response_code(503);
        echo "CRON_SECRET is not configured.\n";
        exit;
    }

    if (!hash_equals('Bearer ' . $secret, typo3_vercel_authorization_header())) {
        http_response_code(401);
        echo "Unauthorized.\n";
        exit;
    }
}

/**
 * Run a TYPO3 CLI command from the project root and capture its combined
 * output. stderr is redirected into stdout so a single blocking read cannot
 * deadlock: reading one pipe to EOF while the child fills a second, unread
 * pipe past its ~64 KB buffer (e.g. a large task stack trace) would otherwise
 * hang the request until the Vercel function times out.
 *
 * @param list<string> $arguments
 * @return array{output: string, exitCode: int}
 */
function typo3_vercel_run_typo3_command(array $arguments): array
{
    $root = dirname(__DIR__);
    $process = proc_open(
        [$root . '/vendor/bin/typo3', ...$arguments],
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

/**
 * Processing-folder target for local-driver storages while object storage is
 * enabled. Default: a combined identifier on the object storage, so image
 * derivatives survive instance replacement (ADR-010; the Blob client now
 * fails loudly on store/auth errors instead of reading them as "not found").
 * 'local' reverts to TYPO3's local default folder (''), 'unmanaged' means
 * "never touch the rows" (null).
 */
function typo3_vercel_local_storage_processing_target(int $objectStorageUid): ?string
{
    $value = typo3_vercel_env('TYPO3_LOCAL_STORAGE_PROCESSING_FOLDER');
    if ($value === null) {
        return $objectStorageUid . ':/_processed_local_/';
    }

    $keyword = strtolower(trim($value));
    if ($keyword === 'unmanaged') {
        return null;
    }
    if ($keyword === 'local') {
        return '';
    }

    return $value;
}

/**
 * Folder part of a "storageUid:/folder/" combined identifier when it points
 * at the given storage; null for other storages or plain folder names.
 */
function typo3_vercel_combined_folder_on_storage(?string $target, int $storageUid): ?string
{
    if ($target === null || preg_match('/^(\d+):(.+)$/', $target, $match) !== 1) {
        return null;
    }
    if ((int)$match[1] !== $storageUid) {
        return null;
    }

    $folder = trim($match[2], '/');
    return $folder === '' ? null : '/' . $folder . '/';
}

function typo3_vercel_object_storage_enabled(): bool
{
    $explicit = typo3_vercel_env('TYPO3_OBJECT_STORAGE_ENABLED');
    if ($explicit !== null) {
        return typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_ENABLED', false);
    }
    return typo3_vercel_env('TYPO3_OBJECT_STORAGE_DRIVER') !== null
        || typo3_vercel_env('TYPO3_S3_BUCKET') !== null
        || typo3_vercel_bool_env('TYPO3_BLOB_ENABLED', false)
        || typo3_vercel_env('BLOB_READ_WRITE_TOKEN') !== null
        || typo3_vercel_env('BLOB_STORE_ID') !== null;
}

function typo3_vercel_object_storage_driver(): string
{
    $driver = strtolower((string)typo3_vercel_env('TYPO3_OBJECT_STORAGE_DRIVER', ''));
    if (in_array($driver, ['vercel_blob', 'blob', 'vercel-blob'], true)) {
        return 'vercel_blob';
    }
    if (in_array($driver, ['vercel_s3', 's3', 's3-compatible'], true)) {
        return 'vercel_s3';
    }
    if (typo3_vercel_bool_env('TYPO3_BLOB_ENABLED', false)
        || typo3_vercel_env('BLOB_READ_WRITE_TOKEN') !== null
        || typo3_vercel_env('BLOB_STORE_ID') !== null
    ) {
        return 'vercel_blob';
    }
    return 'vercel_s3';
}

function typo3_vercel_object_storage_setup(): array
{
    $driverName = typo3_vercel_object_storage_driver();
    $configuration = match ($driverName) {
        'vercel_blob' => typo3_vercel_blob_object_storage_configuration(),
        'vercel_s3' => typo3_vercel_s3_object_storage_configuration(),
        default => throw new RuntimeException('Unsupported object storage driver: ' . $driverName),
    };

    return [
        'driver' => $driverName,
        'configuration' => $configuration,
        'storageUid' => (int)typo3_vercel_first_env(['TYPO3_OBJECT_STORAGE_STORAGE_UID', 'TYPO3_BLOB_STORAGE_UID', 'TYPO3_S3_STORAGE_UID'], '2'),
        'storageName' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_STORAGE_NAME', 'TYPO3_BLOB_STORAGE_NAME', 'TYPO3_S3_STORAGE_NAME'],
            $driverName === 'vercel_blob' ? 'Vercel Blob uploads' : 'Object storage uploads'
        ),
        'makeDefault' => typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_MAKE_DEFAULT', typo3_vercel_bool_env('TYPO3_S3_MAKE_DEFAULT', true)),
        'processingFolder' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_PROCESSING_FOLDER', 'TYPO3_BLOB_PROCESSING_FOLDER', 'TYPO3_S3_PROCESSING_FOLDER'],
            '_processed_'
        ),
        'label' => $driverName === 'vercel_blob'
            ? 'Vercel Blob'
            : 'S3-compatible bucket ' . ($configuration['bucket'] ?? ''),
    ];
}

function typo3_vercel_s3_object_storage_configuration(): array
{
    $bucket = typo3_vercel_env('TYPO3_S3_BUCKET');
    if ($bucket === null) {
        fwrite(STDERR, "TYPO3_S3_BUCKET is required when object storage is enabled.\n");
        exit(1);
    }

    $accessKey = typo3_vercel_env('TYPO3_S3_ACCESS_KEY_ID') ?? typo3_vercel_env('AWS_ACCESS_KEY_ID');
    $secretKey = typo3_vercel_env('TYPO3_S3_SECRET_ACCESS_KEY') ?? typo3_vercel_env('AWS_SECRET_ACCESS_KEY');
    $useInstanceCredentials = typo3_vercel_bool_env('TYPO3_S3_USE_INSTANCE_CREDENTIALS', false);
    if (!$useInstanceCredentials && ($accessKey === null || $secretKey === null)) {
        fwrite(STDERR, "TYPO3_S3_ACCESS_KEY_ID and TYPO3_S3_SECRET_ACCESS_KEY are required unless TYPO3_S3_USE_INSTANCE_CREDENTIALS=1.\n");
        exit(1);
    }

    $publicBaseUrl = typo3_vercel_env('TYPO3_S3_PUBLIC_BASE_URL');
    $signedUrlTtl = (int)typo3_vercel_env('TYPO3_S3_SIGNED_URL_TTL', '0');
    if ($publicBaseUrl === null && $signedUrlTtl <= 0) {
        fwrite(STDERR, "TYPO3_S3_PUBLIC_BASE_URL is required for public TYPO3 assets. Alternatively set TYPO3_S3_SIGNED_URL_TTL for private signed URLs.\n");
        exit(1);
    }

    return [
        'bucket' => $bucket,
        'region' => typo3_vercel_env('TYPO3_S3_REGION', 'auto'),
        'endpoint' => typo3_vercel_env('TYPO3_S3_ENDPOINT', ''),
        'accessKey' => $accessKey ?? '',
        'secretKey' => $secretKey ?? '',
        'prefix' => typo3_vercel_env('TYPO3_S3_PREFIX', ''),
        'publicBaseUrl' => $publicBaseUrl ?? '',
        'signedUrlTtl' => (string)$signedUrlTtl,
        'pathStyleEndpoint' => typo3_vercel_bool_env('TYPO3_S3_PATH_STYLE_ENDPOINT', true) ? '1' : '0',
        'defaultFolder' => typo3_vercel_env('TYPO3_S3_DEFAULT_FOLDER', 'user_upload'),
        'cacheControl' => typo3_vercel_env('TYPO3_S3_CACHE_CONTROL', 'public, max-age=31536000, immutable'),
        'caseSensitive' => typo3_vercel_bool_env('TYPO3_S3_CASE_SENSITIVE', true) ? '1' : '0',
    ];
}

function typo3_vercel_blob_object_storage_configuration(): array
{
    $access = strtolower((string)typo3_vercel_env('TYPO3_BLOB_ACCESS', 'public'));
    if (!in_array($access, ['public', 'private'], true)) {
        fwrite(STDERR, "TYPO3_BLOB_ACCESS must be public or private.\n");
        exit(1);
    }

    return [
        'storeId' => typo3_vercel_env('TYPO3_BLOB_STORE_ID') ?? typo3_vercel_env('BLOB_STORE_ID', ''),
        'access' => $access,
        'tokenEnvName' => typo3_vercel_env('TYPO3_BLOB_TOKEN_ENV_NAME', 'BLOB_READ_WRITE_TOKEN'),
        'prefix' => typo3_vercel_env('TYPO3_BLOB_PREFIX', 'typo3/'),
        'publicBaseUrl' => typo3_vercel_env('TYPO3_BLOB_PUBLIC_BASE_URL', ''),
        'apiUrl' => typo3_vercel_env('TYPO3_BLOB_API_URL') ?? typo3_vercel_env('VERCEL_BLOB_API_URL', 'https://vercel.com/api/blob'),
        'defaultFolder' => typo3_vercel_env('TYPO3_BLOB_DEFAULT_FOLDER', 'user_upload'),
        'cacheControlMaxAge' => typo3_vercel_env('TYPO3_BLOB_CACHE_CONTROL_MAX_AGE', '3600'),
        'processedCacheControlMaxAge' => typo3_vercel_env('TYPO3_BLOB_PROCESSED_CACHE_CONTROL_MAX_AGE', '31536000'),
        'processingFolder' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_PROCESSING_FOLDER', 'TYPO3_BLOB_PROCESSING_FOLDER'],
            '_processed_'
        ),
        'caseSensitive' => typo3_vercel_bool_env('TYPO3_BLOB_CASE_SENSITIVE', true) ? '1' : '0',
    ];
}

function typo3_vercel_database_config(): array
{
    $url = typo3_vercel_env('DATABASE_URL')
        ?? typo3_vercel_env('POSTGRES_URL')
        ?? typo3_vercel_env('MYSQL_URL');

    if ($url !== null) {
        return typo3_vercel_database_config_from_url($url);
    }

    $driver = typo3_vercel_env(
        'TYPO3_DB_DRIVER',
        typo3_vercel_is_vercel_runtime() ? 'pdo_sqlite' : 'mysqli',
    );

    if (in_array($driver, ['sqlite', 'pdo_sqlite'], true)) {
        return [
            'charset' => 'utf8',
            'driver' => 'pdo_sqlite',
            'path' => typo3_vercel_env(
                'TYPO3_DB_PATH',
                typo3_vercel_env(
                    'TYPO3_DB_DBNAME',
                    typo3_vercel_is_vercel_runtime() ? '/tmp/typo3/camino.sqlite' : '/tmp/typo3/typo3.sqlite',
                ),
            ),
        ];
    }

    if (in_array($driver, ['pdo_pgsql', 'pgsql', 'postgres', 'postgresql'], true)) {
        $config = [
            'charset' => 'utf8',
            'dbname' => typo3_vercel_env('TYPO3_DB_DBNAME', 'verceldb'),
            'driver' => 'pdo_pgsql',
            'host' => typo3_vercel_env('TYPO3_DB_HOST', 'localhost'),
            'password' => typo3_vercel_env('TYPO3_DB_PASSWORD', ''),
            'port' => (int)typo3_vercel_env('TYPO3_DB_PORT', '5432'),
            'user' => typo3_vercel_env('TYPO3_DB_USERNAME', 'postgres'),
        ];
        $sslmode = typo3_vercel_env('TYPO3_DB_SSLMODE');
        if ($sslmode !== null) {
            $config['sslmode'] = $sslmode;
        }
        return $config;
    }

    return [
        'charset' => 'utf8mb4',
        'dbname' => typo3_vercel_env('TYPO3_DB_DBNAME', 'typo3'),
        'defaultTableOptions' => [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'driver' => $driver,
        'host' => typo3_vercel_env('TYPO3_DB_HOST', 'localhost'),
        'password' => typo3_vercel_env('TYPO3_DB_PASSWORD', ''),
        'port' => (int)typo3_vercel_env('TYPO3_DB_PORT', '3306'),
        'user' => typo3_vercel_env('TYPO3_DB_USERNAME', 'typo3'),
    ];
}

function typo3_vercel_database_config_from_url(string $url): array
{
    // Canonical SQLite URLs (sqlite:///abs/path, sqlite://rel/path, sqlite:/abs/path)
    // are not reliably parseable by parse_url(): the standard triple-slash form makes
    // parse_url() return false, and the two-slash form silently drops the first path
    // segment into the host. Handle the scheme up front instead.
    if (preg_match('#^sqlite:(//)?(.*)$#i', $url, $sqliteMatch) === 1) {
        $path = $sqliteMatch[2];
        // Normalise a leading run of slashes so that authority slashes and an
        // absolute path both collapse to a single leading slash.
        $path = '/' . ltrim($path, '/');
        return [
            'charset' => 'utf8',
            'driver' => 'pdo_sqlite',
            'path' => $path !== '/' ? $path : '/tmp/typo3/typo3.sqlite',
        ];
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'])) {
        throw new RuntimeException('DATABASE_URL is not a valid URL.');
    }

    $scheme = strtolower((string)$parts['scheme']);
    $query = [];
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $dbname = isset($parts['path']) ? rawurldecode(ltrim((string)$parts['path'], '/')) : '';
    $user = isset($parts['user']) ? rawurldecode((string)$parts['user']) : '';
    $password = isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : '';
    $host = rawurldecode((string)($parts['host'] ?? 'localhost'));

    if (in_array($scheme, ['postgres', 'postgresql'], true)) {
        $config = [
            'charset' => 'utf8',
            'dbname' => $dbname,
            'driver' => 'pdo_pgsql',
            'host' => $host,
            'password' => $password,
            'port' => (int)($parts['port'] ?? 5432),
            'user' => $user,
        ];
        if (isset($query['sslmode']) && is_string($query['sslmode'])) {
            $config['sslmode'] = $query['sslmode'];
        }
        return $config;
    }

    if (in_array($scheme, ['mysql', 'mariadb'], true)) {
        // Only accept a MySQL-family driver override here. The image bakes
        // TYPO3_DB_DRIVER=pdo_sqlite as its demo default, and inheriting that
        // for a mysql:// URL would produce a broken sqlite/MySQL hybrid.
        $driver = typo3_vercel_env('TYPO3_DB_DRIVER', 'mysqli');
        if (!in_array($driver, ['mysqli', 'pdo_mysql'], true)) {
            $driver = 'mysqli';
        }
        return [
            'charset' => 'utf8mb4',
            'dbname' => $dbname,
            'defaultTableOptions' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'driver' => $driver,
            'host' => $host,
            'password' => $password,
            'port' => (int)($parts['port'] ?? 3306),
            'user' => $user,
        ];
    }

    throw new RuntimeException(sprintf('Unsupported database URL scheme "%s".', $scheme));
}

function typo3_vercel_setup_driver(array $database): string
{
    return match ($database['driver'] ?? '') {
        'pdo_sqlite' => 'sqlite',
        'pdo_pgsql', 'pgsql', 'postgres', 'postgresql' => 'postgres',
        default => (string)($database['driver'] ?? 'mysqli'),
    };
}

function typo3_vercel_pdo_dsn(array $database): string
{
    return match ($database['driver'] ?? '') {
        'pdo_sqlite' => 'sqlite:' . $database['path'],
        'pdo_pgsql' => typo3_vercel_pgsql_dsn($database),
        default => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['dbname'],
            $database['charset'] ?? 'utf8mb4',
        ),
    };
}

function typo3_vercel_pgsql_dsn(array $database): string
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $database['host'],
        $database['port'],
        $database['dbname'],
    );

    if (isset($database['sslmode']) && is_string($database['sslmode']) && $database['sslmode'] !== '') {
        $dsn .= ';sslmode=' . $database['sslmode'];
    }

    return $dsn;
}

/**
 * Exception-mode PDO connection for the configured database. SQLite parent
 * directories are created first because the runtime /tmp tree may not exist
 * yet on a fresh instance.
 *
 * @param array<int, mixed> $options
 */
function typo3_vercel_pdo(array $database, array $options = []): PDO
{
    if (($database['driver'] ?? '') === 'pdo_sqlite') {
        $directory = dirname((string)($database['path'] ?? ''));
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    return new PDO(
        typo3_vercel_pdo_dsn($database),
        (string)($database['user'] ?? null),
        (string)($database['password'] ?? null),
        $options + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * Detect the "table is missing" family of driver messages across SQLite,
 * Postgres, and MySQL/MariaDB.
 */
function typo3_vercel_is_missing_table_error(string $message): bool
{
    foreach (['no such table', 'does not exist', "doesn't exist"] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function typo3_vercel_cache_backend(): string
{
    $default = typo3_vercel_is_vercel_runtime() ? 'file' : 'database';
    $backend = strtolower((string)typo3_vercel_env('TYPO3_CACHE_BACKEND', $default));

    if ($backend === 'redis') {
        if (typo3_vercel_redis_cache_base_options() !== null && extension_loaded('redis')) {
            return 'redis';
        }
        if (typo3_vercel_bool_env('TYPO3_REDIS_REQUIRED', false)) {
            throw new RuntimeException(
                'TYPO3_CACHE_BACKEND=redis was requested, but no usable Redis TCP/TLS connection or ext-redis is available.',
                1783520001,
            );
        }
        return 'file';
    }

    return match ($backend) {
        'file', 'filesystem', 'local' => 'file',
        default => 'database',
    };
}

function typo3_vercel_redis_url(): ?string
{
    return typo3_vercel_env('TYPO3_REDIS_URL')
        ?? typo3_vercel_env('REDIS_URL')
        ?? typo3_vercel_env('UPSTASH_REDIS_URL')
        ?? typo3_vercel_env('KV_URL');
}

function typo3_vercel_redis_cache_base_options(): ?array
{
    $url = typo3_vercel_redis_url();
    if ($url === null) {
        return typo3_vercel_redis_component_options();
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['redis', 'rediss'], true)) {
        return null;
    }

    $database = 0;
    if (isset($parts['path']) && trim((string)$parts['path'], '/') !== '') {
        $database = (int)trim((string)$parts['path'], '/');
    }

    $hostname = rawurldecode((string)$parts['host']);
    if ($scheme === 'rediss') {
        $hostname = 'tls://' . $hostname;
    }

    $options = [
        'hostname' => $hostname,
        'port' => (int)($parts['port'] ?? ($scheme === 'rediss' ? 6380 : 6379)),
        'database' => $database,
        'connectionTimeout' => typo3_vercel_int_env('TYPO3_REDIS_CONNECTION_TIMEOUT', 1, 0, 10),
        'persistentConnection' => typo3_vercel_bool_env('TYPO3_REDIS_PERSISTENT_CONNECTION', true),
    ];

    if (isset($parts['user']) && $parts['user'] !== '') {
        $options['username'] = rawurldecode((string)$parts['user']);
    }
    if (isset($parts['pass']) && $parts['pass'] !== '') {
        $options['password'] = rawurldecode((string)$parts['pass']);
    }

    return $options;
}

function typo3_vercel_redis_component_options(): ?array
{
    $host = typo3_vercel_env('TYPO3_REDIS_HOST')
        ?? typo3_vercel_env('REDIS_HOST')
        ?? typo3_vercel_env('REDIS_ENDPOINT')
        ?? typo3_vercel_env('UPSTASH_REDIS_HOST')
        ?? typo3_vercel_env('UPSTASH_REDIS_ENDPOINT');

    if ($host === null) {
        return null;
    }

    $tls = typo3_vercel_bool_env(
        'TYPO3_REDIS_TLS',
        typo3_vercel_bool_env(
            'REDIS_TLS',
            typo3_vercel_bool_env('UPSTASH_REDIS_TLS', false),
        ),
    );

    $host = rawurldecode($host);
    $hostPort = null;
    $hostDatabase = null;
    $hostUsername = null;
    $hostPassword = null;

    if (preg_match('#^(redis|rediss|tls)://#i', $host) === 1) {
        $parts = parse_url($host);
        if ($parts !== false && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower((string)$parts['scheme']);
            $tls = in_array($scheme, ['rediss', 'tls'], true);
            $host = rawurldecode((string)$parts['host']);
            $hostPort = isset($parts['port']) ? (int)$parts['port'] : null;
            if (isset($parts['path']) && trim((string)$parts['path'], '/') !== '') {
                $hostDatabase = (int)trim((string)$parts['path'], '/');
            }
            if (isset($parts['user']) && $parts['user'] !== '') {
                $hostUsername = rawurldecode((string)$parts['user']);
            }
            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $hostPassword = rawurldecode((string)$parts['pass']);
            }
        }
    }

    $port = typo3_vercel_env('TYPO3_REDIS_PORT')
        ?? typo3_vercel_env('REDIS_PORT')
        ?? typo3_vercel_env('UPSTASH_REDIS_PORT')
        ?? ($hostPort !== null ? (string)$hostPort : null)
        ?? ($tls ? '6380' : '6379');

    $database = (int)(
        typo3_vercel_env('TYPO3_REDIS_DATABASE')
        ?? typo3_vercel_env('REDIS_DATABASE')
        ?? typo3_vercel_env('REDIS_DB')
        ?? ($hostDatabase !== null ? (string)$hostDatabase : null)
        ?? '0'
    );

    $options = [
        'hostname' => ($tls ? 'tls://' : '') . $host,
        'port' => (int)$port,
        'database' => $database,
        'connectionTimeout' => typo3_vercel_int_env('TYPO3_REDIS_CONNECTION_TIMEOUT', 1, 0, 10),
        'persistentConnection' => typo3_vercel_bool_env('TYPO3_REDIS_PERSISTENT_CONNECTION', true),
    ];

    $username = typo3_vercel_env('TYPO3_REDIS_USERNAME')
        ?? typo3_vercel_env('REDIS_USERNAME')
        ?? typo3_vercel_env('REDIS_USER')
        ?? typo3_vercel_env('UPSTASH_REDIS_USERNAME')
        ?? $hostUsername;
    if ($username !== null) {
        $options['username'] = $username;
    }

    $password = typo3_vercel_env('TYPO3_REDIS_PASSWORD')
        ?? typo3_vercel_env('REDIS_PASSWORD')
        ?? typo3_vercel_env('REDIS_PASS')
        ?? typo3_vercel_env('UPSTASH_REDIS_PASSWORD')
        ?? typo3_vercel_env('UPSTASH_REDIS_TOKEN')
        ?? $hostPassword;
    if ($password !== null) {
        $options['password'] = $password;
    }

    return $options;
}

function typo3_vercel_redis_cache_configuration(
    string $cacheName,
    bool $compression = false,
    array $extraOptions = [],
    bool $deploymentScoped = false,
): array
{
    $options = typo3_vercel_redis_cache_base_options() ?? [];
    $prefix = typo3_vercel_env('TYPO3_REDIS_PREFIX', 'typo3-camino-vercel:') ?? 'typo3-camino-vercel:';
    $keyPrefix = $prefix . $cacheName . ':';
    if ($deploymentScoped) {
        // CLI deployments never set VERCEL_GIT_COMMIT_SHA, which once left
        // every deployment sharing one page cache (stale rendered HTML
        // survived every redeploy). Fall back to the revision the deploy
        // script exports, then to the per-deployment VERCEL_URL.
        $deployment = typo3_vercel_env('VERCEL_GIT_COMMIT_SHA')
            ?? typo3_vercel_env('TYPO3_DEPLOYMENT_REVISION')
            ?? typo3_vercel_env('VERCEL_URL');
        $deployment = is_string($deployment)
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $deployment)
            : null;
        if (is_string($deployment) && $deployment !== '') {
            $keyPrefix .= 'deploy-' . substr($deployment, 0, 32) . ':';
        }
    }
    $options['keyPrefix'] = $keyPrefix;
    $options['compression'] = $compression;
    $options['readTimeout'] = typo3_vercel_int_env('TYPO3_REDIS_READ_TIMEOUT', 2, 1, 30);

    return [
        'backend' => 'Webconsulting\\Typo3VercelStorage\\Cache\\Backend\\VercelRedisBackend',
        'options' => array_replace($options, $extraOptions),
    ];
}

function typo3_vercel_cache_configurations(): array
{
    if (typo3_vercel_cache_backend() === 'redis') {
        return [
            'hash' => typo3_vercel_redis_cache_configuration('hash'),
            'pages' => typo3_vercel_redis_cache_configuration('pages', true, [], true),
            'rootline' => typo3_vercel_redis_cache_configuration('rootline', true, [
                'defaultLifetime' => 2592000,
            ]),
        ];
    }

    if (typo3_vercel_cache_backend() === 'file') {
        return [
            'hash' => [
                'backend' => 'Webconsulting\\Typo3VercelStorage\\Cache\\Backend\\RuntimeFileBackend',
            ],
            'pages' => [
                'backend' => 'Webconsulting\\Typo3VercelStorage\\Cache\\Backend\\RuntimeFileBackend',
            ],
            'rootline' => [
                'backend' => 'Webconsulting\\Typo3VercelStorage\\Cache\\Backend\\RuntimeFileBackend',
                'options' => [
                    'defaultLifetime' => 2592000,
                ],
            ],
        ];
    }

    return [
        'hash' => [
            'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
        ],
        'pages' => [
            'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
            'options' => [
                'compression' => true,
            ],
        ],
        'rootline' => [
            'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\Typo3DatabaseBackend',
            'options' => [
                'compression' => true,
            ],
        ],
    ];
}

function typo3_vercel_log_configuration(): array
{
    $phpErrorLogWriter = 'TYPO3\\CMS\\Core\\Log\\Writer\\PhpErrorLogWriter';
    $fileWriter = 'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter';
    $logToPhpErrorLog = typo3_vercel_bool_env('TYPO3_LOG_TO_PHP_ERROR_LOG', typo3_vercel_is_vercel_runtime());

    $configuration = [
        'TYPO3' => [
            'CMS' => [
                'deprecations' => [
                    'writerConfiguration' => [
                        'notice' => [
                            $fileWriter => [
                                'disabled' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    if ($logToPhpErrorLog) {
        $configuration['writerConfiguration']['warning'][$phpErrorLogWriter] = [];
        // Instances are disposable: file logs under /tmp are lost on scale-in
        // and would duplicate every warning already reaching the platform log.
        $configuration['writerConfiguration']['warning'][$fileWriter] = [
            'disabled' => true,
        ];
        $configuration['ApacheSolrForTypo3']['Solr']['writerConfiguration']['error'][$phpErrorLogWriter] = [];
    }

    return $configuration;
}

function typo3_vercel_locking_configuration(bool $isVercelRuntime): array
{
    $runtimeLockDir = typo3_vercel_env('TYPO3_RUNTIME_LOCK_DIR');
    if ($runtimeLockDir === null && $isVercelRuntime) {
        $runtimeLockDir = '/tmp/typo3/var/lock';
    }

    if ($runtimeLockDir === null) {
        return [];
    }

    // TYPO3's lockFileDir setting is intentionally relative to the project
    // path. Convert the absolute /tmp runtime directory into the relative path
    // TYPO3 expects so locks never fall back to the immutable image directory.
    return [
        'strategies' => [
            'TYPO3\\CMS\\Core\\Locking\\FileLockStrategy' => [
                'lockFileDir' => typo3_vercel_project_relative_path($runtimeLockDir),
            ],
        ],
    ];
}

/**
 * First configured alias of the internal Solr service URL, or null when the
 * deployment has no internal Solr service.
 */
function typo3_vercel_solr_service_url(): ?string
{
    foreach (['TYPO3_SOLR_SERVICE_URL', 'SOLR_SERVICE_URL', 'TYPO3_SOLR_INTERNAL_URL', 'SOLR_INTERNAL_URL'] as $name) {
        $value = typo3_vercel_env($name);
        if ($value !== null) {
            return $value;
        }
    }

    return null;
}

function typo3_vercel_internal_solr_proxy_enabled(): bool
{
    if (!typo3_vercel_bool_env('TYPO3_SOLR_ENABLED', false)) {
        return false;
    }

    if (!typo3_vercel_bool_env('TYPO3_SOLR_APP_PROXY_ENABLED', true)) {
        return false;
    }

    return typo3_vercel_solr_service_url() !== null;
}

function typo3_vercel_http_configuration(): array
{
    if (!typo3_vercel_internal_solr_proxy_enabled()) {
        return [];
    }

    $timeout = typo3_vercel_int_env('TYPO3_SOLR_HTTP_TIMEOUT', 20, 5, 30);

    return [
        'connect_timeout' => typo3_vercel_int_env('TYPO3_SOLR_HTTP_CONNECT_TIMEOUT', 2, 1, 10),
        'timeout' => $timeout,
    ];
}

function typo3_vercel_settings(): array
{
    $database = typo3_vercel_database_config();
    $debug = typo3_vercel_env('TYPO3_DEBUG', '0') === '1';
    $isVercelRuntime = typo3_vercel_is_vercel_runtime();

    return [
        'BE' => [
            'debug' => $debug,
            'installToolPassword' => typo3_vercel_env('TYPO3_INSTALL_TOOL_PASSWORD_HASH', ''),
            'passwordHashing' => [
                'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
                'options' => [],
            ],
        ],
        'DB' => [
            'Connections' => [
                'Default' => $database,
            ],
        ],
        'EXTENSIONS' => [
            'backend' => [
                'backendFavicon' => '',
                'backendLogo' => '',
                'loginBackgroundImage' => '',
                'loginFootnote' => '',
                'loginHighlightColor' => '',
                'loginLogo' => '',
                'loginLogoAlt' => '',
            ],
        ],
        'FE' => [
            'cacheHash' => [
                'enforceValidation' => true,
            ],
            'debug' => $debug,
            'disableNoCacheParameter' => true,
            'passwordHashing' => [
                'className' => 'TYPO3\\CMS\\Core\\Crypto\\PasswordHashing\\Argon2iPasswordHash',
                'options' => [],
            ],
        ],
        'GFX' => [
            'processor_enabled' => typo3_vercel_bool_env('TYPO3_GFX_PROCESSOR_ENABLED', true),
            'processor_path' => typo3_vercel_env('TYPO3_GFX_PROCESSOR_PATH', '/usr/bin/'),
            'processor' => typo3_vercel_env('TYPO3_GFX_PROCESSOR', 'ImageMagick'),
            'processor_effects' => typo3_vercel_bool_env('TYPO3_GFX_PROCESSOR_EFFECTS', false),
            'processor_colorspace' => typo3_vercel_env('TYPO3_GFX_PROCESSOR_COLORSPACE', ''),
            'processor_stripColorProfileByDefault' => true,
            'processor_stripColorProfileParameters' => ['+profile', '*'],
            'jpg_quality' => typo3_vercel_int_env('TYPO3_GFX_JPG_QUALITY', 85, 1, 100),
            'webp_quality' => typo3_vercel_int_env('TYPO3_GFX_WEBP_QUALITY', 85, 1, 100),
            'avif_quality' => typo3_vercel_int_env('TYPO3_GFX_AVIF_QUALITY', 75, 1, 100),
        ],
        'HTTP' => typo3_vercel_http_configuration(),
        'LOG' => typo3_vercel_log_configuration(),
        'MAIL' => [
            'transport' => typo3_vercel_env('TYPO3_MAIL_TRANSPORT', 'sendmail'),
            'transport_sendmail_command' => typo3_vercel_env('TYPO3_MAIL_SENDMAIL_COMMAND', '/usr/sbin/sendmail -t -i'),
            'transport_smtp_encrypt' => typo3_vercel_env('TYPO3_MAIL_SMTP_ENCRYPT', ''),
            'transport_smtp_password' => typo3_vercel_env('TYPO3_MAIL_SMTP_PASSWORD', ''),
            'transport_smtp_server' => typo3_vercel_env('TYPO3_MAIL_SMTP_SERVER', ''),
            'transport_smtp_username' => typo3_vercel_env('TYPO3_MAIL_SMTP_USERNAME', ''),
        ],
        'SYS' => [
            'UTF8filesystem' => true,
            'caching' => [
                'cacheConfigurations' => typo3_vercel_cache_configurations(),
            ],
            'devIPmask' => '',
            'displayErrors' => $debug ? 1 : 0,
            'encryptionKey' => typo3_vercel_env('TYPO3_ENCRYPTION_KEY', ''),
            'exceptionalErrors' => 4096,
            'features' => [
                'frontend.cache.autoTagging' => true,
                'security.system.enforceAllowedFileExtensions' => true,
            ],
            'locking' => typo3_vercel_locking_configuration($isVercelRuntime),
            'productionExceptionHandler' => typo3_vercel_bool_env('TYPO3_LOG_PRODUCTION_EXCEPTIONS', $isVercelRuntime)
                ? 'Webconsulting\\Typo3VercelStorage\\Error\\VercelProductionExceptionHandler'
                : 'TYPO3\\CMS\\Core\\Error\\ProductionExceptionHandler',
            'sitename' => typo3_vercel_env('TYPO3_PROJECT_NAME', 'TYPO3 Camino'),
            'systemMaintainers' => typo3_vercel_system_maintainers(),
            // The pattern must be wrapped in a single non-capturing group. TYPO3 evaluates
            // it as preg_match('/^' . $pattern . '$/i', $host), so a bare a|b|c alternation
            // would anchor ^ only to the first branch and $ only to the last, leaving the
            // middle branches unanchored and matching hostile Host headers such as
            // "vercel.app.attacker.com" or "localhost.attacker.com".
            'trustedHostsPattern' => typo3_vercel_env('TYPO3_TRUSTED_HOSTS_PATTERN', '(?:(.+\\.)?vercel\\.app|localhost(:[0-9]+)?|127\\.0\\.0\\.1(:[0-9]+)?|0\\.0\\.0\\.0(:[0-9]+)?)'),
            'reverseProxyIP' => typo3_vercel_env('TYPO3_REVERSE_PROXY_IP', $isVercelRuntime ? '*' : ''),
            'reverseProxyHeaderMultiValue' => typo3_vercel_env('TYPO3_REVERSE_PROXY_HEADER_MULTI_VALUE', 'none'),
            'reverseProxySSL' => typo3_vercel_env('TYPO3_REVERSE_PROXY_SSL', ''),
        ],
    ];
}
