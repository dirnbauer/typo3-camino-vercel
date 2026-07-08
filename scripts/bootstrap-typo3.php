#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/typo3-env.php';

$root = dirname(__DIR__);
chdir($root);

$database = typo3_vercel_database_config();
$settingsPath = $root . '/config/system/settings.php';
$siteConfigPath = $root . '/config/sites/camino/config.yaml';
$settingsTemplate = is_file($settingsPath) ? file_get_contents($settingsPath) : null;
$siteConfigTemplate = is_file($siteConfigPath) ? file_get_contents($siteConfigPath) : null;

// Serialize bootstrap across concurrent cold-start instances that share the same
// external database. Without this, two instances can both observe an empty
// database and run `typo3 setup --force` in parallel, racing on schema DDL and
// admin-user creation. SQLite databases are per-instance, so no lock is needed.
// The lock is held for the lifetime of $bootstrapLock (its connection).
$bootstrapLock = typo3_vercel_acquire_bootstrap_lock($database);

if (typo3_vercel_has_backend_admin_user($database)) {
    fwrite(STDOUT, "TYPO3 database already contains a backend admin user; skipping setup.\n");
    exit(0);
}

$adminPassword = typo3_vercel_env('TYPO3_SETUP_ADMIN_PASSWORD');
if ($adminPassword === null) {
    fwrite(STDERR, "TYPO3_SETUP_ADMIN_PASSWORD is required when bootstrapping an empty database.\n");
    exit(1);
}

$env = $_ENV;
foreach ($_SERVER as $key => $value) {
    if (is_string($value) && getenv($key) !== false) {
        $env[$key] = $value;
    }
}

$env['TYPO3_DB_DRIVER'] = typo3_vercel_setup_driver($database);
$env['TYPO3_DB_HOST'] = (string)($database['host'] ?? '');
$env['TYPO3_DB_PORT'] = (string)($database['port'] ?? '');
$env['TYPO3_DB_DBNAME'] = (string)($database['dbname'] ?? ($database['path'] ?? ''));
$env['TYPO3_DB_USERNAME'] = (string)($database['user'] ?? '');
$env['TYPO3_DB_PASSWORD'] = (string)($database['password'] ?? '');
if (isset($database['sslmode']) && is_string($database['sslmode']) && $database['sslmode'] !== '') {
    $env['TYPO3_DB_SSLMODE'] = $database['sslmode'];
    $env['PGSSLMODE'] = $database['sslmode'];
}
if (($database['driver'] ?? '') === 'pdo_pgsql' && $env['TYPO3_DB_DBNAME'] !== '') {
    // TYPO3 setup unsets dbname while listing databases; libpq otherwise defaults to dbname=user.
    $env['PGDATABASE'] = $env['TYPO3_DB_DBNAME'];
}
$env['TYPO3_SETUP_ADMIN_PASSWORD'] = $adminPassword;
$env['TYPO3_CONTEXT'] = typo3_vercel_env('TYPO3_CONTEXT', 'Production/Vercel');

$command = [
    $root . '/vendor/bin/typo3',
    'setup',
    '--force',
    '--no-interaction',
    '--server-type=' . typo3_vercel_env('TYPO3_SERVER_TYPE', 'apache'),
    '--driver=' . $env['TYPO3_DB_DRIVER'],
    '--admin-username=' . typo3_vercel_env('TYPO3_SETUP_ADMIN_USERNAME', 'admin'),
    '--admin-email=' . typo3_vercel_env('TYPO3_SETUP_ADMIN_EMAIL', 'admin@example.com'),
    '--project-name=' . typo3_vercel_env('TYPO3_PROJECT_NAME', 'TYPO3 Camino'),
];

if (($database['driver'] ?? '') === 'pdo_sqlite') {
    $command[] = '--dbname=' . $env['TYPO3_DB_DBNAME'];
} else {
    $command[] = '--host=' . $env['TYPO3_DB_HOST'];
    $command[] = '--port=' . $env['TYPO3_DB_PORT'];
    $command[] = '--dbname=' . $env['TYPO3_DB_DBNAME'];
    $command[] = '--username=' . $env['TYPO3_DB_USERNAME'];
    // The password is intentionally NOT passed via --password: it would appear in
    // the process list (/proc/<pid>/cmdline) for the duration of setup. The
    // `typo3 setup` command reads it from the TYPO3_DB_PASSWORD environment
    // variable already exported into $env above.
}

// Read the raw distribution value so an explicit empty string selects the
// bare `--create-site` path. typo3_vercel_env() would map '' back to the
// default, making the create-site branch unreachable.
$distribution = getenv('TYPO3_SETUP_DISTRIBUTION');
if ($distribution === false) {
    $distribution = 'theme_camino';
}
if ($distribution !== '' && strtolower($distribution) !== 'none') {
    $command[] = '--distribution=' . $distribution;
} else {
    $command[] = '--create-site=' . typo3_vercel_env('TYPO3_SETUP_CREATE_SITE', '/');
}

fwrite(STDOUT, "Bootstrapping TYPO3 database...\n");
$exitCode = typo3_vercel_run($command, $env);

if ($exitCode === 0 && ($database['driver'] ?? '') === 'pdo_sqlite') {
    typo3_vercel_copy_generated_sqlite_database($database);
}

if ($settingsTemplate !== null) {
    file_put_contents($settingsPath, $settingsTemplate);
}
if ($siteConfigTemplate !== null) {
    file_put_contents($siteConfigPath, $siteConfigTemplate);
}

exit($exitCode);

/**
 * Acquire a cross-instance bootstrap lock for external databases.
 *
 * Returns the PDO connection that holds the lock (keep it referenced so the lock
 * is not released early), or null when locking is not applicable/possible.
 */
function typo3_vercel_acquire_bootstrap_lock(array $database): ?PDO
{
    $driver = $database['driver'] ?? '';
    if ($driver === 'pdo_sqlite') {
        return null;
    }

    try {
        $pdo = new PDO(
            typo3_vercel_pdo_dsn($database),
            (string)($database['user'] ?? null),
            (string)($database['password'] ?? null),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $exception) {
        // The database itself may not exist yet (e.g. MySQL error 1049). In that
        // case there is nothing to lock; fall back to lock-free bootstrap.
        return null;
    }

    $timeout = typo3_vercel_int_env('TYPO3_BOOTSTRAP_LOCK_TIMEOUT', 120, 1, 600);

    try {
        if ($driver === 'pdo_pgsql') {
            // Advisory lock keyed on a stable 64-bit constant derived from the string
            // "typo3-camino-vercel:bootstrap". pg_advisory_lock blocks until granted.
            $pdo->exec('SET lock_timeout = ' . ($timeout * 1000));
            $pdo->query('SELECT pg_advisory_lock(3937019283746501)');
        } else {
            $statement = $pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
            $statement->execute(['name' => 'typo3-camino-vercel:bootstrap', 'timeout' => $timeout]);
        }
    } catch (PDOException $exception) {
        // If the lock cannot be taken (e.g. permission), continue without it rather
        // than blocking the container from starting.
        fwrite(STDERR, sprintf("Could not acquire bootstrap lock (%s); continuing without it.\n", $exception->getMessage()));
        return null;
    }

    return $pdo;
}

function typo3_vercel_has_backend_admin_user(array $database): bool
{
    $retries = typo3_vercel_int_env('TYPO3_DB_CONNECT_RETRIES', 20, 1, 200);
    $delay = typo3_vercel_int_env('TYPO3_DB_CONNECT_RETRY_DELAY', 2, 0, 60);

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            if (($database['driver'] ?? '') === 'pdo_sqlite') {
                $directory = dirname((string)$database['path']);
                if (!is_dir($directory)) {
                    mkdir($directory, 0775, true);
                }
            }
            $pdo = new PDO(
                typo3_vercel_pdo_dsn($database),
                (string)($database['user'] ?? null),
                (string)($database['password'] ?? null),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $result = $pdo->query("SELECT 1 FROM be_users WHERE username <> '_cli_' AND admin = 1 AND deleted = 0 LIMIT 1");
            return $result !== false && $result->fetchColumn() !== false;
        } catch (PDOException $exception) {
            if (typo3_vercel_is_empty_database_error($exception->getMessage())) {
                return false;
            }
            if ($attempt === $retries) {
                fwrite(STDERR, sprintf("Database connection failed: %s\n", $exception->getMessage()));
                exit(1);
            }
            sleep($delay);
        }
    }

    return false;
}

/**
 * Detect the "database is reachable but not yet initialised" state across engines.
 * Postgres/SQLite report a missing table; MySQL/MariaDB report an unknown database
 * (error 1049) when the schema itself has not been created yet.
 */
function typo3_vercel_is_empty_database_error(string $message): bool
{
    foreach (['no such table', 'does not exist', "doesn't exist", 'Unknown database', 'Base table or view not found'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function typo3_vercel_run(array $command, array $env): int
{
    $process = proc_open(
        $command,
        [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
        dirname(__DIR__),
        $env,
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start TYPO3 setup process.\n");
        return 1;
    }

    return proc_close($process);
}

function typo3_vercel_copy_generated_sqlite_database(array $database): void
{
    $files = glob(dirname(__DIR__) . '/var/sqlite/cms-*.sqlite') ?: [];
    if ($files === []) {
        return;
    }

    usort($files, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
    $target = (string)$database['path'];
    $targetDirectory = dirname($target);
    if (!is_dir($targetDirectory)) {
        mkdir($targetDirectory, 0775, true);
    }
    copy($files[0], $target);
}
