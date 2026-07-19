<?php

declare(strict_types=1);

/**
 * Temporary diagnostic endpoint for the ADR-010 Blob cross-storage
 * processing investigation. Protected by CRON_SECRET. It exercises the
 * Blob client primitives with both credential modes (request OIDC and the
 * read/write token) and resolves the local storage's processing folder the
 * same way TYPO3 core does during image processing.
 */

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/typo3-env.php';

$secret = getenv('CRON_SECRET');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($secret === false || $secret === '' || !hash_equals('Bearer ' . $secret, $authorization)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$report = [
    'context' => [
        'oidcHeaderPresent' => isset($_SERVER['HTTP_X_VERCEL_OIDC_TOKEN']),
        'oidcEnvPresent' => getenv('VERCEL_OIDC_TOKEN') !== false,
        'rwTokenPresent' => getenv('BLOB_READ_WRITE_TOKEN') !== false,
        'blobStoreIdPresent' => getenv('BLOB_STORE_ID') !== false,
        'vercelEnv' => getenv('VERCEL_ENV') ?: null,
        'localProcessingEnv' => getenv('TYPO3_LOCAL_STORAGE_PROCESSING_FOLDER') ?: null,
    ],
];

$classLoader = require $root . '/vendor/autoload.php';

use Webconsulting\Typo3VercelBlobStorage\Authentication\BlobCredentials;
use Webconsulting\Typo3VercelBlobStorage\Client\VercelBlobClient;

$storeId = BlobCredentials::resolveStoreId(null);
$report['context']['resolvedStoreId'] = $storeId !== null ? substr($storeId, 0, 6) . '…' : null;

function lab_probe_client(?string $token, string $label, string $storeId): array
{
    $out = ['tokenPrefix' => $token !== null ? substr($token, 0, 14) . '…' : null];
    if ($token === null) {
        $out['skipped'] = 'no token in this mode';
        return $out;
    }
    $client = new VercelBlobClient($storeId, 'public', $token);
    $probeKey = 'typo3/_lab_/' . $label . '-' . bin2hex(random_bytes(4)) . '.txt';

    foreach ([
        'headMissing' => fn () => $client->head('typo3/_lab_/definitely-missing-' . bin2hex(random_bytes(4))) === null
            ? 'null (treated as not found)'
            : 'unexpected-hit',
        'list' => fn () => count($client->listPathnames('typo3/', 3)) . ' keys',
        'put' => function () use ($client, $probeKey) {
            $client->put($probeKey, 'lab probe', 'text/plain', 60);
            return 'ok';
        },
        'headWritten' => fn () => $client->head($probeKey) !== null ? 'found' : 'MISSING-AFTER-PUT',
        'delete' => function () use ($client, $probeKey) {
            $client->delete([$probeKey]);
            return 'ok';
        },
    ] as $step => $call) {
        try {
            $out[$step] = $call();
        } catch (\Throwable $e) {
            $out[$step] = 'EXCEPTION ' . $e::class . ': ' . substr($e->getMessage(), 0, 220);
        }
    }

    return $out;
}

if ($storeId !== null) {
    $report['probeOidc'] = lab_probe_client(BlobCredentials::requestOidcToken(), 'oidc', $storeId);
    $rwToken = getenv('BLOB_READ_WRITE_TOKEN');
    $report['probeRw'] = lab_probe_client(is_string($rwToken) && $rwToken !== '' ? $rwToken : null, 'rw', $storeId);
}

// Full TYPO3 processing-path resolution, as core does it at render time.
try {
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run();
    \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, false);
} catch (\Throwable $e) {
    $report['bootstrap'] = 'EXCEPTION ' . $e::class . ': ' . substr($e->getMessage(), 0, 300);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $storageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\StorageRepository::class);
    $local = $storageRepository->findByUid(1);
    $blob = $storageRepository->findByUid(2);
    $local->setEvaluatePermissions(false);
    $blob->setEvaluatePermissions(false);
    $report['localStorage'] = [
        'processingfolderColumn' => $local->getStorageRecord()['processingfolder'] ?? null,
        'isOnline' => $local->isOnline(),
    ];
    $report['blobStorage'] = [
        'driver' => $blob->getDriverType(),
        'isOnline' => $blob->isOnline(),
    ];

    $folder = $local->getProcessingFolder();
    $report['resolvedProcessingFolder'] = [
        'class' => $folder::class,
        'identifier' => $folder->getIdentifier(),
        'storageUid' => $folder->getStorage()->getUid(),
    ];
} catch (\Throwable $e) {
    $report['processingFolderResolution'] = 'EXCEPTION ' . $e::class . ': ' . substr($e->getMessage(), 0, 300);
}

try {
    $resourceFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class);
    $file = $resourceFactory->getFileObjectFromCombinedIdentifier('1:/camino/max-kukurudziak-mrnvspgbdk0-unsplash.webp');
    $report['heroFile'] = [
        'identifier' => $file->getIdentifier(),
        'existsOnDisk' => $file->exists(),
        'width' => $file->getProperty('width'),
        'height' => $file->getProperty('height'),
    ];
    $processed = $file->process(
        \TYPO3\CMS\Core\Resource\ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
        ['width' => '200c', 'height' => '200c']
    );
    $report['processedResult'] = [
        'usesOriginalFile' => $processed->usesOriginalFile(),
        'identifier' => $processed->getIdentifier(),
        'storageUid' => $processed->getStorage()->getUid(),
        'publicUrl' => $processed->getPublicUrl(),
        'exists' => $processed->exists(),
    ];
} catch (\Throwable $e) {
    $report['processing'] = 'EXCEPTION ' . $e::class . ': ' . substr($e->getMessage(), 0, 400);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
