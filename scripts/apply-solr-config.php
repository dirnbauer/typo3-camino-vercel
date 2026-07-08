#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file is missing; cannot apply Solr site config.\n");
    exit(1);
}

require $autoload;

if (!solr_bool_env('TYPO3_SOLR_ENABLED', solr_has_connection_env())) {
    fwrite(STDOUT, "TYPO3 Solr is not enabled; skipping Solr site config.\n");
    exit(0);
}

$read = solr_connection_from_env('TYPO3_SOLR', 'SOLR');
$write = solr_connection_from_env('TYPO3_SOLR_WRITE', 'SOLR_WRITE', $read);
$useWriteConnection = solr_bool_env('TYPO3_SOLR_USE_WRITE_CONNECTION', $write !== $read);
$siteConfigPaths = solr_site_config_paths($root);

foreach ($siteConfigPaths as $siteConfigPath) {
    if (!is_file($siteConfigPath)) {
        fwrite(STDERR, sprintf("TYPO3 site config not found: %s\n", $siteConfigPath));
        exit(1);
    }

    $site = Yaml::parseFile($siteConfigPath) ?: [];
    if (!is_array($site)) {
        fwrite(STDERR, sprintf("TYPO3 site config is not a YAML map: %s\n", $siteConfigPath));
        exit(1);
    }

    solr_apply_site_dependencies($site);
    solr_apply_site_base($site);

    $site['solr_enabled_read'] = true;
    $site['solr_scheme_read'] = $read['scheme'];
    $site['solr_host_read'] = $read['host'];
    $site['solr_port_read'] = (string)$read['port'];
    $site['solr_path_read'] = $read['path'];
    $site['solr_use_write_connection'] = $useWriteConnection;
    solr_apply_credentials($site, $read, 'read');

    if ($useWriteConnection) {
        $site['solr_scheme_write'] = $write['scheme'];
        $site['solr_host_write'] = $write['host'];
        $site['solr_port_write'] = (string)$write['port'];
        $site['solr_path_write'] = $write['path'];
        solr_apply_credentials($site, $write, 'write');
    }

    $site['languages'] = solr_apply_language_cores((array)($site['languages'] ?? []), $read['core']);
    file_put_contents($siteConfigPath, Yaml::dump($site, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    fwrite(STDOUT, sprintf("Applied TYPO3 Solr site config to %s.\n", $siteConfigPath));
}

function solr_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function solr_bool_env(string $name, bool $default): bool
{
    $value = solr_env($name);
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function solr_has_connection_env(): bool
{
    return solr_env('TYPO3_SOLR_URL') !== null
        || solr_env('SOLR_URL') !== null
        || solr_env('TYPO3_SOLR_HOST') !== null
        || solr_env('SOLR_HOST') !== null;
}

function solr_connection_from_env(string $prefix, string $fallbackPrefix, ?array $fallback = null): array
{
    $url = solr_env($prefix . '_URL') ?? solr_env($fallbackPrefix . '_URL');
    $parsed = $url !== null ? solr_parse_url($url) : [];

    $scheme = solr_env($prefix . '_SCHEME') ?? solr_env($fallbackPrefix . '_SCHEME') ?? ($parsed['scheme'] ?? $fallback['scheme'] ?? 'https');
    $host = solr_env($prefix . '_HOST') ?? solr_env($fallbackPrefix . '_HOST') ?? ($parsed['host'] ?? $fallback['host'] ?? null);
    if ($host === null) {
        fwrite(STDERR, sprintf("%s_HOST or %s_URL is required when TYPO3 Solr is enabled.\n", $prefix, $prefix));
        exit(1);
    }

    $port = solr_env($prefix . '_PORT') ?? solr_env($fallbackPrefix . '_PORT') ?? ($parsed['port'] ?? $fallback['port'] ?? ($scheme === 'https' ? 443 : 8983));
    $path = solr_env($prefix . '_PATH') ?? solr_env($fallbackPrefix . '_PATH') ?? ($parsed['path'] ?? $fallback['path'] ?? '/');
    $core = solr_env($prefix . '_CORE') ?? solr_env($fallbackPrefix . '_CORE') ?? ($parsed['core'] ?? $fallback['core'] ?? 'core_en');
    $username = solr_env($prefix . '_USERNAME') ?? solr_env($fallbackPrefix . '_USERNAME') ?? ($parsed['username'] ?? $fallback['username'] ?? null);
    $password = solr_env($prefix . '_PASSWORD') ?? solr_env($fallbackPrefix . '_PASSWORD') ?? ($parsed['password'] ?? $fallback['password'] ?? null);

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => (int)$port,
        'path' => solr_normalize_path($path),
        'core' => $core,
        'username' => $username,
        'password' => $password,
    ];
}

function solr_parse_url(string $url): array
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'])) {
        fwrite(STDERR, "TYPO3_SOLR_URL is not a valid URL.\n");
        exit(1);
    }

    $path = $parts['path'] ?? '/';
    $core = null;
    $basePath = $path;
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
    if ($segments !== [] && preg_match('/^core[_-]/', end($segments)) === 1) {
        $core = array_pop($segments);
        $basePath = $segments === [] ? '/' : '/' . implode('/', $segments) . '/';
    }

    return [
        'scheme' => $parts['scheme'] ?? 'https',
        'host' => $parts['host'],
        'port' => $parts['port'] ?? null,
        'path' => $basePath,
        'core' => $core,
        'username' => isset($parts['user']) ? rawurldecode((string)$parts['user']) : null,
        'password' => isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : null,
    ];
}

function solr_normalize_path(string $path): string
{
    if ($path === '') {
        return '/';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }
    return $path;
}

function solr_site_config_paths(string $root): array
{
    $identifier = solr_env('TYPO3_SOLR_SITE_IDENTIFIER', 'camino');
    if ($identifier === 'all') {
        return glob($root . '/config/sites/*/config.yaml') ?: [];
    }
    return [$root . '/config/sites/' . $identifier . '/config.yaml'];
}

function solr_apply_site_dependencies(array &$site): void
{
    $dependencies = array_values(array_unique(array_merge((array)($site['dependencies'] ?? []), ['apache-solr-for-typo3/solr'])));
    if (solr_bool_env('TYPO3_SOLR_INCLUDE_STYLESHEETS', true)) {
        $dependencies[] = 'apache-solr-for-typo3/solr-stylesheets';
    }
    $site['dependencies'] = array_values(array_unique($dependencies));
}

function solr_apply_site_base(array &$site): void
{
    $base = solr_env('TYPO3_SOLR_SITE_BASE');
    if ($base !== null) {
        $site['base'] = rtrim($base, '/') . '/';
    }
}

function solr_apply_credentials(array &$site, array $connection, string $scope): void
{
    if (($connection['username'] ?? null) !== null) {
        $site['solr_username_' . $scope] = $connection['username'];
    }
    if (($connection['password'] ?? null) !== null) {
        $site['solr_password_' . $scope] = $connection['password'];
    }
}

function solr_apply_language_cores(array $languages, string $defaultCore): array
{
    foreach ($languages as $index => $language) {
        if (!is_array($language)) {
            continue;
        }
        $languageId = (string)($language['languageId'] ?? $index);
        $specificCore = solr_env('TYPO3_SOLR_CORE_LANGUAGE_' . $languageId)
            ?? solr_env('SOLR_CORE_LANGUAGE_' . $languageId);
        $language['solr_core_read'] = $specificCore ?? $language['solr_core_read'] ?? $defaultCore;
        $languages[$index] = $language;
    }

    return $languages;
}
