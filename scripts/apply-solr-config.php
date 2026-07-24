#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/typo3-env.php';

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file is missing; cannot apply Solr site config.\n");
    exit(1);
}

require $autoload;

if (!typo3_vercel_solr_enabled()) {
    fwrite(STDOUT, "TYPO3 Solr is not enabled; skipping Solr site config.\n");
    exit(0);
}

$read = solr_connection_from_env('TYPO3_SOLR', 'SOLR');
$write = solr_connection_from_env('TYPO3_SOLR_WRITE', 'SOLR_WRITE', $read);
$useWriteConnection = typo3_vercel_bool_env('TYPO3_SOLR_USE_WRITE_CONNECTION', $write !== $read);
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

    $applySiteSet = typo3_vercel_bool_env('TYPO3_SOLR_APPLY_SITE_SET', false);
    if ($applySiteSet) {
        solr_apply_site_dependencies($site);
    }
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
    fwrite(STDOUT, sprintf(
        "Applied TYPO3 Solr connection config to %s.%s\n",
        $siteConfigPath,
        $applySiteSet ? ' Solr site set dependencies are enabled.' : ' Solr site set dependencies are disabled.'
    ));
}

function solr_connection_from_env(string $prefix, string $fallbackPrefix, ?array $fallback = null): array
{
    $url = typo3_vercel_env($prefix . '_URL') ?? typo3_vercel_env($fallbackPrefix . '_URL');
    $usesServiceBinding = false;
    if ($url === null) {
        $url = solr_service_url_from_env($prefix, $fallbackPrefix);
        $usesServiceBinding = $url !== null;
    }
    $parsed = $url !== null ? solr_parse_url($url) : [];

    $scheme = typo3_vercel_env($prefix . '_SCHEME') ?? typo3_vercel_env($fallbackPrefix . '_SCHEME') ?? ($parsed['scheme'] ?? $fallback['scheme'] ?? 'https');
    $host = typo3_vercel_env($prefix . '_HOST') ?? typo3_vercel_env($fallbackPrefix . '_HOST') ?? ($parsed['host'] ?? $fallback['host'] ?? null);
    if ($host === null) {
        fwrite(STDERR, sprintf("%s_HOST or %s_URL is required when TYPO3 Solr is enabled.\n", $prefix, $prefix));
        exit(1);
    }

    $defaultPort = $usesServiceBinding ? ($scheme === 'https' ? 443 : 80) : ($scheme === 'https' ? 443 : 8983);
    $port = typo3_vercel_env($prefix . '_PORT') ?? typo3_vercel_env($fallbackPrefix . '_PORT') ?? ($parsed['port'] ?? $fallback['port'] ?? $defaultPort);
    $path = typo3_vercel_env($prefix . '_PATH') ?? typo3_vercel_env($fallbackPrefix . '_PATH') ?? ($parsed['path'] ?? $fallback['path'] ?? '/');
    $core = typo3_vercel_env($prefix . '_CORE') ?? typo3_vercel_env($fallbackPrefix . '_CORE') ?? ($parsed['core'] ?? $fallback['core'] ?? 'core_en');
    $username = typo3_vercel_env($prefix . '_USERNAME') ?? typo3_vercel_env($fallbackPrefix . '_USERNAME') ?? ($parsed['username'] ?? $fallback['username'] ?? null);
    $password = typo3_vercel_env($prefix . '_PASSWORD') ?? typo3_vercel_env($fallbackPrefix . '_PASSWORD') ?? ($parsed['password'] ?? $fallback['password'] ?? null);

    if ($usesServiceBinding && typo3_vercel_bool_env('TYPO3_SOLR_APP_PROXY_ENABLED', true)) {
        return [
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => (int)(typo3_vercel_env('TYPO3_SOLR_APP_PROXY_PORT') ?? typo3_vercel_env('PORT', '80')),
            'path' => '/api/solr-proxy.php/',
            'core' => $core,
            'username' => null,
            'password' => null,
        ];
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => (int)$port,
        'path' => solr_normalize_site_path($path),
        'core' => $core,
        'username' => $username,
        'password' => $password,
    ];
}

function solr_service_url_from_env(string $prefix, string $fallbackPrefix): ?string
{
    if (typo3_vercel_env($prefix . '_HOST') !== null || typo3_vercel_env($fallbackPrefix . '_HOST') !== null) {
        return null;
    }

    // The read prefix collapses to the shared alias cascade; the write prefix
    // consults its own *_SERVICE_URL aliases before the internal-URL fallback.
    $serviceUrl = $prefix === 'TYPO3_SOLR'
        ? typo3_vercel_solr_service_url()
        : (typo3_vercel_env($prefix . '_SERVICE_URL')
            ?? typo3_vercel_env($fallbackPrefix . '_SERVICE_URL')
            ?? typo3_vercel_env('TYPO3_SOLR_INTERNAL_URL')
            ?? typo3_vercel_env('SOLR_INTERNAL_URL'));

    if ($serviceUrl === null) {
        return null;
    }

    $path = typo3_vercel_env($prefix . '_PATH') ?? typo3_vercel_env($fallbackPrefix . '_PATH') ?? '/';
    $core = typo3_vercel_env($prefix . '_CORE') ?? typo3_vercel_env($fallbackPrefix . '_CORE') ?? 'core_en';

    return rtrim($serviceUrl, '/') . solr_normalize_path($path) . rawurlencode($core);
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
        if (($segments[count($segments) - 1] ?? '') === 'solr') {
            array_pop($segments);
        }
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

function solr_normalize_site_path(string $path): string
{
    $path = solr_normalize_path($path);
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

    if (($segments[count($segments) - 1] ?? '') === 'solr') {
        array_pop($segments);
        return $segments === [] ? '/' : '/' . implode('/', $segments) . '/';
    }

    return $path;
}

function solr_site_config_paths(string $root): array
{
    $identifier = typo3_vercel_env('TYPO3_SOLR_SITE_IDENTIFIER', 'camino');
    if ($identifier === 'all') {
        return glob($root . '/config/sites/*/config.yaml') ?: [];
    }
    return [$root . '/config/sites/' . $identifier . '/config.yaml'];
}

function solr_apply_site_dependencies(array &$site): void
{
    $siteSet = typo3_vercel_env('TYPO3_SOLR_SITE_SET', 'webconsulting/typo3-vercel-solr-demo');
    $stylesheetSiteSet = typo3_vercel_env('TYPO3_SOLR_STYLESHEET_SITE_SET', 'webconsulting/typo3-vercel-solr-demo-stylesheets');
    $dependencies = array_values(array_filter(
        (array)($site['dependencies'] ?? []),
        static fn (mixed $dependency): bool => !in_array((string)$dependency, [
            'apache-solr-for-typo3/solr',
            'apache-solr-for-typo3/solr-stylesheets',
        ], true),
    ));
    $dependencies[] = $siteSet;
    if (typo3_vercel_bool_env('TYPO3_SOLR_INCLUDE_STYLESHEETS', true)) {
        $dependencies[] = $stylesheetSiteSet;
    }
    $site['dependencies'] = array_values(array_unique(array_filter($dependencies, static fn (mixed $dependency): bool => (string)$dependency !== '')));
}

function solr_apply_site_base(array &$site): void
{
    $base = typo3_vercel_env('TYPO3_SOLR_SITE_BASE');
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
    $internalService = typo3_vercel_solr_service_url() !== null;
    $internalCores = [
        '0' => 'core_en',
        '1' => 'core_de',
        '2' => 'core_es',
        '3' => 'core_zh',
        '4' => 'core_hu',
    ];

    foreach ($languages as $index => $language) {
        if (!is_array($language)) {
            continue;
        }
        $languageId = (string)($language['languageId'] ?? $index);
        $specificCore = typo3_vercel_env('TYPO3_SOLR_CORE_LANGUAGE_' . $languageId)
            ?? typo3_vercel_env('SOLR_CORE_LANGUAGE_' . $languageId);
        $language['solr_core_read'] = $specificCore
            ?? ($internalService ? ($internalCores[$languageId] ?? null) : null)
            ?? $language['solr_core_read']
            ?? $defaultCore;
        $languages[$index] = $language;
    }

    return $languages;
}
