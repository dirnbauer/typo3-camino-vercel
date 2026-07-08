#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prime the persistent opcache file cache (opcache.file_cache in docker/php.ini)
 * at image build time. Because the Vercel container filesystem is read-only
 * except /tmp, opcache cannot populate the cache at runtime, so a new instance
 * would otherwise recompile the whole TYPO3 codebase on its first request. This
 * compiles the request-path PHP files into the shared cache once, during build.
 *
 * It never fails the build: unparseable or environment-specific files are skipped.
 */

if (!function_exists('opcache_compile_file')) {
    fwrite(STDERR, "opcache_compile_file() is unavailable; skipping warmup.\n");
    exit(0);
}

$root = dirname(__DIR__);
$targets = [
    $root . '/vendor',
    $root . '/packages',
    $root . '/public',
];

$skipSegments = ['/Tests/', '/tests/', '/Documentation/', '/.git/'];

$compiled = 0;
$skipped = 0;

foreach ($targets as $base) {
    if (!is_dir($base)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        foreach ($skipSegments as $segment) {
            if (str_contains($path, $segment)) {
                continue 2;
            }
        }

        try {
            if (@opcache_compile_file($path)) {
                $compiled++;
            } else {
                $skipped++;
            }
        } catch (\Throwable $exception) {
            $skipped++;
        }
    }
}

fwrite(STDOUT, sprintf("opcache warmup compiled %d files (%d skipped).\n", $compiled, $skipped));
exit(0);
