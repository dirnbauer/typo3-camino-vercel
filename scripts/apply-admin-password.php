#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/typo3-env.php';

$root = dirname(__DIR__);
chdir($root);

$adminPassword = typo3_vercel_env('TYPO3_SETUP_ADMIN_PASSWORD');
if ($adminPassword === null) {
    exit(0);
}

$adminUsername = typo3_vercel_env('TYPO3_SETUP_ADMIN_USERNAME', 'admin') ?? 'admin';
$database = typo3_vercel_database_config();

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
} catch (PDOException $exception) {
    $message = $exception->getMessage();
    if (str_contains($message, 'no such table') || str_contains($message, 'does not exist') || str_contains($message, "doesn't exist")) {
        fwrite(STDOUT, "TYPO3 backend user table is not available; skipping admin password update.\n");
        exit(0);
    }

    fwrite(STDERR, sprintf("Failed to connect to TYPO3 database while applying admin password: %s\n", $message));
    exit(1);
}

$passwordHash = password_hash($adminPassword, PASSWORD_ARGON2I);
if ($passwordHash === false) {
    fwrite(STDERR, "Failed to hash TYPO3 admin password.\n");
    exit(1);
}

// Scope the update to the live (non-deleted) account only. Without the
// `deleted = 0` guard, a previously soft-deleted "admin" row would be matched
// and force-undeleted on every boot, resurrecting stale accounts and creating
// duplicate active users with the same username.
$statement = $pdo->prepare(
    'UPDATE be_users
        SET password = :password,
            admin = 1,
            disable = 0
      WHERE username = :username
        AND deleted = 0',
);
$statement->execute([
    'password' => $passwordHash,
    'username' => $adminUsername,
]);

if ($statement->rowCount() === 0) {
    fwrite(STDERR, sprintf("TYPO3 backend user \"%s\" was not found (or is deleted); admin password was not applied.\n", $adminUsername));
    exit(1);
}

fwrite(STDOUT, sprintf("Applied TYPO3 backend password for user \"%s\".\n", $adminUsername));
