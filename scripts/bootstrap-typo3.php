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

if (typo3_vercel_has_be_users_table($database)) {
    fwrite(STDOUT, "TYPO3 database already contains be_users; skipping setup.\n");
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
}

$distribution = typo3_vercel_env('TYPO3_SETUP_DISTRIBUTION', 'theme_camino');
if ($distribution !== '') {
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

function typo3_vercel_has_be_users_table(array $database): bool
{
    $retries = (int)typo3_vercel_env('TYPO3_DB_CONNECT_RETRIES', '20');
    $delay = (int)typo3_vercel_env('TYPO3_DB_CONNECT_RETRY_DELAY', '2');

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
            $pdo->query('SELECT 1 FROM be_users LIMIT 1');
            return true;
        } catch (PDOException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'no such table') || str_contains($message, 'does not exist') || str_contains($message, "doesn't exist")) {
                return false;
            }
            if ($attempt === $retries) {
                fwrite(STDERR, sprintf("Database connection failed: %s\n", $message));
                exit(1);
            }
            sleep($delay);
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
