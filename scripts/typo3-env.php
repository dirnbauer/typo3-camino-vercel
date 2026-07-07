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

function typo3_vercel_int_env(string $name, int $default, int $min, int $max): int
{
    $value = typo3_vercel_env($name);
    if ($value === null || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int)$value));
}

function typo3_vercel_is_vercel_runtime(): bool
{
    return typo3_vercel_env('VERCEL') === '1'
        || typo3_vercel_env('VERCEL_URL') !== null;
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
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'])) {
        throw new RuntimeException('DATABASE_URL is not a valid URL.');
    }

    $scheme = strtolower((string)$parts['scheme']);
    $query = [];
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $dbname = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : '';
    $user = isset($parts['user']) ? rawurldecode((string)$parts['user']) : '';
    $password = isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : '';
    $host = (string)($parts['host'] ?? 'localhost');

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
        return [
            'charset' => 'utf8mb4',
            'dbname' => $dbname,
            'defaultTableOptions' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'driver' => typo3_vercel_env('TYPO3_DB_DRIVER', 'mysqli'),
            'host' => $host,
            'password' => $password,
            'port' => (int)($parts['port'] ?? 3306),
            'user' => $user,
        ];
    }

    if ($scheme === 'sqlite') {
        return [
            'charset' => 'utf8',
            'driver' => 'pdo_sqlite',
            'path' => $dbname !== '' ? '/' . $dbname : '/tmp/typo3/typo3.sqlite',
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

function typo3_vercel_redis_cache_configuration(string $cacheName, bool $compression = false, array $extraOptions = []): array
{
    $options = typo3_vercel_redis_cache_base_options() ?? [];
    $prefix = typo3_vercel_env('TYPO3_REDIS_PREFIX', 'typo3-camino-vercel:') ?? 'typo3-camino-vercel:';
    $options['keyPrefix'] = $prefix . $cacheName . ':';
    $options['compression'] = $compression;

    return [
        'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\RedisBackend',
        'options' => array_replace($options, $extraOptions),
    ];
}

function typo3_vercel_cache_configurations(): array
{
    if (typo3_vercel_cache_backend() === 'redis') {
        return [
            'hash' => typo3_vercel_redis_cache_configuration('hash'),
            'pages' => typo3_vercel_redis_cache_configuration('pages', true),
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
            'processor' => typo3_vercel_env('TYPO3_GFX_PROCESSOR', 'GraphicsMagick'),
            'processor_effects' => typo3_vercel_bool_env('TYPO3_GFX_PROCESSOR_EFFECTS', false),
            'processor_colorspace' => typo3_vercel_env('TYPO3_GFX_PROCESSOR_COLORSPACE', ''),
            'processor_stripColorProfileByDefault' => true,
            'processor_stripColorProfileParameters' => ['+profile', '*'],
            'jpg_quality' => typo3_vercel_int_env('TYPO3_GFX_JPG_QUALITY', 85, 1, 100),
            'webp_quality' => typo3_vercel_int_env('TYPO3_GFX_WEBP_QUALITY', 85, 1, 100),
            'avif_quality' => typo3_vercel_int_env('TYPO3_GFX_AVIF_QUALITY', 75, 1, 100),
        ],
        'LOG' => [
            'TYPO3' => [
                'CMS' => [
                    'deprecations' => [
                        'writerConfiguration' => [
                            'notice' => [
                                'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => [
                                    'disabled' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
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
            'sitename' => typo3_vercel_env('TYPO3_PROJECT_NAME', 'TYPO3 Camino'),
            'systemMaintainers' => [1],
            'trustedHostsPattern' => typo3_vercel_env('TYPO3_TRUSTED_HOSTS_PATTERN', '(.+\\.)?vercel\\.app|localhost(:[0-9]+)?|127\\.0\\.0\\.1(:[0-9]+)?|0\\.0\\.0\\.0(:[0-9]+)?'),
            'reverseProxyIP' => typo3_vercel_env('TYPO3_REVERSE_PROXY_IP', $isVercelRuntime ? '*' : ''),
            'reverseProxyHeaderMultiValue' => typo3_vercel_env('TYPO3_REVERSE_PROXY_HEADER_MULTI_VALUE', 'none'),
            'reverseProxySSL' => typo3_vercel_env('TYPO3_REVERSE_PROXY_SSL', ''),
        ],
    ];
}
