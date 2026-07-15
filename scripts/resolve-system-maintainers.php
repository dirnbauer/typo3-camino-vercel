#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/typo3-env.php';

$configuredMaintainers = typo3_vercel_env('TYPO3_SYSTEM_MAINTAINERS');
if ($configuredMaintainers !== null) {
    fwrite(STDOUT, implode(',', typo3_vercel_system_maintainers()));
    exit(0);
}

$adminUsername = typo3_vercel_env('TYPO3_SETUP_ADMIN_USERNAME', 'admin') ?? 'admin';

try {
    $maintainers = typo3_vercel_resolve_system_maintainers(
        typo3_vercel_database_config(),
        $adminUsername,
    );
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf(
        "Could not resolve the TYPO3 system maintainer from the database: %s\n",
        $exception->getMessage(),
    ));
    exit(1);
}

if ($maintainers === []) {
    fwrite(STDERR, sprintf(
        "The active backend admin user \"%s\" was not found; keeping the fallback system maintainer UID.\n",
        $adminUsername,
    ));
    exit(1);
}

fwrite(STDOUT, implode(',', $maintainers));
