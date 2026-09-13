#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Repeatable client-side HTTP benchmark for the deployment comparison.
 *
 * Usage:
 *   php scripts/benchmark-platforms.php --runs=10 --output=result.json \
 *     Vercel=https://example.vercel.app Railway=https://example.up.railway.app
 */

const DEFAULT_RUNS = 10;

/**
 * @return array{runs: int, output: ?string, targets: array<string, string>}
 * @param list<string> $arguments
 * @return array<string, mixed>
 */
function parseArguments(array $arguments): array
{
    $runs = DEFAULT_RUNS;
    $output = null;
    $targets = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if (str_starts_with($argument, '--runs=')) {
            $runs = (int)substr($argument, strlen('--runs='));
            continue;
        }
        if (str_starts_with($argument, '--output=')) {
            $output = substr($argument, strlen('--output='));
            continue;
        }
        if (!str_contains($argument, '=')) {
            throw new InvalidArgumentException(sprintf('Expected label=https://url, got "%s".', $argument));
        }

        [$label, $url] = explode('=', $argument, 2);
        $label = trim($label);
        $url = rtrim(trim($url), '/');
        if ($label === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('Invalid benchmark target "%s".', $argument));
        }
        $scheme = (string)parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf('Target "%s" must use HTTP or HTTPS.', $label));
        }
        $targets[$label] = $url;
    }

    if ($runs < 3 || $runs > 100) {
        throw new InvalidArgumentException('--runs must be between 3 and 100.');
    }
    if ($targets === []) {
        throw new InvalidArgumentException('Provide at least one label=https://url target.');
    }

    return ['runs' => $runs, 'output' => $output, 'targets' => $targets];
}

/**
 * @param list<string> $headers
 * @return array{
 *   code: int,
 *   dns_ms: float,
 *   connect_ms: float,
 *   tls_ms: float,
 *   ttfb_ms: float,
 *   total_ms: float,
 *   bytes: int,
 *   remote_ip: string,
 *   response_headers: array<string, string>,
 *   error: string
 * }
 */
function measureRequest(string $url, array $headers = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize cURL.');
    }

    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'typo3-camino-platform-benchmark/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (!str_contains($line, ':')) {
                return $length;
            }
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
            return $length;
        },
    ]);

    $body = curl_exec($handle);
    $error = curl_error($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $dnsTime = curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME);
    $connectTime = curl_getinfo($handle, CURLINFO_CONNECT_TIME);
    $tlsTime = curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);
    $ttfbTime = curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
    $totalTime = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
    $remoteIp = curl_getinfo($handle, CURLINFO_PRIMARY_IP);
    curl_close($handle);

    return [
        'code' => $httpCode,
        'dns_ms' => round($dnsTime * 1000, 3),
        'connect_ms' => round($connectTime * 1000, 3),
        'tls_ms' => round($tlsTime * 1000, 3),
        'ttfb_ms' => round($ttfbTime * 1000, 3),
        'total_ms' => round($totalTime * 1000, 3),
        'bytes' => is_string($body) ? strlen($body) : 0,
        'remote_ip' => $remoteIp,
        'response_headers' => $responseHeaders,
        'error' => $error,
    ];
}

/**
 * @param list<float> $values
 */
function percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $rank = max(1, (int)ceil($percentile * count($values)));
    return round($values[$rank - 1], 3);
}

/**
 * @param list<array{code: int, ttfb_ms: float, total_ms: float}> $samples
 * @return array{successful: int, failed: int, median_ttfb_ms: ?float, p95_ttfb_ms: ?float, median_total_ms: ?float, p95_total_ms: ?float}
 */
function summarizeSamples(array $samples): array
{
    $successful = array_values(array_filter(
        $samples,
        static fn (array $sample): bool => $sample['code'] >= 200 && $sample['code'] < 400,
    ));

    return [
        'successful' => count($successful),
        'failed' => count($samples) - count($successful),
        'median_ttfb_ms' => percentile(
            array_map(static fn (array $sample): float => $sample['ttfb_ms'], $successful),
            0.5,
        ),
        'p95_ttfb_ms' => percentile(
            array_map(static fn (array $sample): float => $sample['ttfb_ms'], $successful),
            0.95,
        ),
        'median_total_ms' => percentile(
            array_map(static fn (array $sample): float => $sample['total_ms'], $successful),
            0.5,
        ),
        'p95_total_ms' => percentile(
            array_map(static fn (array $sample): float => $sample['total_ms'], $successful),
            0.95,
        ),
    ];
}

/**
 * @return array<string, mixed>
 */
function benchmarkTarget(string $label, string $origin, int $runs): array
{
    fwrite(STDERR, sprintf("Benchmarking %s (%s)\n", $label, $origin));

    $first = measureRequest($origin . '/');
    $warm = [];
    $originSamples = [];
    $health = [];

    for ($run = 0; $run < $runs; ++$run) {
        $warm[] = measureRequest($origin . '/');
        usleep(100_000);
    }

    for ($run = 0; $run < $runs; ++$run) {
        $originSamples[] = measureRequest($origin . '/', [
            'Cache-Control: no-cache',
            'Cookie: typo3-benchmark=1',
        ]);
        usleep(100_000);
    }

    $healthRuns = max(3, min(5, $runs));
    for ($run = 0; $run < $healthRuns; ++$run) {
        $health[] = measureRequest($origin . '/api/health.php', ['Cache-Control: no-cache']);
        usleep(100_000);
    }

    return [
        'label' => $label,
        'origin' => $origin,
        'first_observed' => $first,
        'warm_public' => [
            'summary' => summarizeSamples($warm),
            'samples' => $warm,
        ],
        'uncached_origin' => [
            'summary' => summarizeSamples($originSamples),
            'samples' => $originSamples,
        ],
        'health' => [
            'summary' => summarizeSamples($health),
            'samples' => $health,
        ],
    ];
}

function gitRevision(): string
{
    $revision = [];
    $status = 1;
    exec('git rev-parse --verify HEAD 2>/dev/null', $revision, $status);
    return $status === 0 ? trim((string)($revision[0] ?? 'unknown')) : 'unknown';
}

/**
 * @param array<string, mixed> $result
 */
function printMarkdown(array $result): void
{
    echo "| Platform | First observed | Warm public median/p95 | Origin median/p95 | Health median |\n";
    echo "|---|---:|---:|---:|---:|\n";

    foreach ($result['platforms'] as $platform) {
        $first = $platform['first_observed'];
        $warm = $platform['warm_public']['summary'];
        $origin = $platform['uncached_origin']['summary'];
        $health = $platform['health']['summary'];
        printf(
            "| %s | %.0f ms | %.0f / %.0f ms | %.0f / %.0f ms | %.0f ms |\n",
            $platform['label'],
            $first['total_ms'],
            $warm['median_total_ms'] ?? 0.0,
            $warm['p95_total_ms'] ?? 0.0,
            $origin['median_total_ms'] ?? 0.0,
            $origin['p95_total_ms'] ?? 0.0,
            $health['median_total_ms'] ?? 0.0,
        );
    }
}

/** @var list<string> $argv */
try {
    $configuration = parseArguments($argv);
    $platforms = [];
    foreach ($configuration['targets'] as $label => $origin) {
        $platforms[] = benchmarkTarget($label, $origin, $configuration['runs']);
    }

    $result = [
        'generated_at' => gmdate(DATE_ATOM),
        'git_revision' => gitRevision(),
        'client_timezone' => date_default_timezone_get(),
        'runs_per_request_class' => $configuration['runs'],
        'method' => [
            'first_observed' => 'First request in this benchmark run; not a confirmed cold start.',
            'warm_public' => 'Repeated GET / requests, allowing provider/CDN caching.',
            'uncached_origin' => 'Repeated GET / with a Cookie and Cache-Control: no-cache.',
            'health' => 'Repeated shallow /api/health.php requests.',
        ],
        'platforms' => $platforms,
    ];

    if ($configuration['output'] !== null) {
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($configuration['output'], $encoded . "\n") === false) {
            throw new RuntimeException(sprintf('Could not write %s.', $configuration['output']));
        }
    }

    printMarkdown($result);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}
