<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\Resource\Driver;

use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\StreamableDriverInterface;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use Webconsulting\Typo3ObjectStorageCore\Resource\Driver\ObjectStorageDriverTrait;
use Webconsulting\Typo3VercelBlobStorage\Authentication\BlobCredentials;
use Webconsulting\Typo3VercelBlobStorage\Client\VercelBlobClient;

/**
 * Vercel Blob FAL driver for stateless container runtimes.
 */
final class BlobDriver extends AbstractHierarchicalFilesystemDriver implements StreamableDriverInterface
{
    use ObjectStorageDriverTrait;

    private string $storeId = '';
    private string $access = 'public';
    private string $tokenEnvName = 'BLOB_READ_WRITE_TOKEN';
    private string $token = '';
    private string $apiUrl = 'https://vercel.com/api/blob';
    private ?int $cacheControlMaxAge = 3600;
    private ?int $processedCacheControlMaxAge = 31536000;
    private string $processingFolder = '_processed_';
    private ?VercelBlobClient $client = null;

    public function processConfiguration(): void
    {
        $this->access = strtolower(trim((string)($this->configuration['access'] ?? 'public'))) === 'private' ? 'private' : 'public';
        $this->tokenEnvName = trim((string)($this->configuration['tokenEnvName'] ?? 'BLOB_READ_WRITE_TOKEN')) ?: 'BLOB_READ_WRITE_TOKEN';
        $this->storeId = BlobCredentials::resolveStoreId($this->configuration['storeId'] ?? null, $this->tokenEnvName) ?? '';
        // 'token' is a manual-configuration escape hatch (apply-object-storage.php only writes tokenEnvName); the null path is required for BlobCredentials OIDC resolution.
        $this->token = BlobCredentials::resolveToken(
            $this->configuration['token'] ?? null,
            $this->tokenEnvName,
            $this->storeId !== '',
        ) ?? '';
        if ($this->storeId === '') {
            throw new InvalidConfigurationException(
                'Vercel Blob storage requires a store id. Set BLOB_STORE_ID or configure storeId.',
                1720100101
            );
        }

        $this->apiUrl = rtrim((string)($this->configuration['apiUrl'] ?? 'https://vercel.com/api/blob'), '/') ?: 'https://vercel.com/api/blob';
        $this->prefix = $this->normalizePrefix((string)($this->configuration['prefix'] ?? ''));
        $this->publicBaseUrl = $this->normalizePublicBaseUrl($this->configuration['publicBaseUrl'] ?? null);
        $cacheControlMaxAge = trim((string)($this->configuration['cacheControlMaxAge'] ?? '3600'));
        $this->cacheControlMaxAge = $cacheControlMaxAge === '' ? null : max(0, (int)$cacheControlMaxAge);
        $processedCacheControlMaxAge = trim((string)($this->configuration['processedCacheControlMaxAge'] ?? '31536000'));
        $this->processedCacheControlMaxAge = $processedCacheControlMaxAge === ''
            ? null
            : max(0, (int)$processedCacheControlMaxAge);
        $this->processingFolder = trim((string)($this->configuration['processingFolder'] ?? '_processed_'), '/');
        $this->defaultFolder = $this->canonicalizeAndCheckFolderIdentifier(
            '/' . trim((string)($this->configuration['defaultFolder'] ?? 'user_upload'), '/') . '/'
        );

        if ($this->access !== 'public') {
            $this->capabilities->removeCapability(Capabilities::CAPABILITY_PUBLIC);
        }
    }

    public function getPublicUrl(string $identifier): ?string
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        $key = $this->keyFromFileIdentifier($identifier);

        if ($this->publicBaseUrl !== null) {
            return $this->publicBaseUrl . $this->encodeKeyForUrl($key);
        }

        if ($this->access === 'public') {
            return $this->client()->publicUrl($key);
        }

        return null;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $key = $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($fileIdentifier));
        $this->client()->delete([$key]);
        $this->invalidateStatCache($key);
        return true;
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $head = $this->headObject($this->keyFromFileIdentifier($fileIdentifier));
        if ($head === null) {
            throw new FileDoesNotExistException('File "' . $fileIdentifier . '" does not exist.', 1720010014);
        }

        // A FAL content fingerprint must change when the remote object changes, but
        // downloading a multi-gigabyte Blob merely to populate sys_file.sha1 would
        // defeat direct uploads and can exceed the Vercel runtime disk limit.
        $fingerprint = implode('|', [
            (string)($head['etag'] ?? ''),
            (string)($head['size'] ?? ''),
            (string)($head['uploadedAt'] ?? ''),
            $fileIdentifier,
        ]);
        try {
            $hash = hash($hashAlgorithm, $fingerprint);
        } catch (\ValueError $exception) {
            throw new FileOperationErrorException('Could not hash file "' . $fileIdentifier . '".', 1720010008);
        }
        return $hash;
    }

    public function getFileContents(string $fileIdentifier): string
    {
        try {
            return $this->client()->getContents(
                $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($fileIdentifier))
            );
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '404')) {
                return '';
            }
            throw $exception;
        }
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
        $response = $this->client()->downloadToFile($this->keyFromFileIdentifier($fileIdentifier), $temporaryPath);
        @touch($temporaryPath, $this->timestampFromLastModified($response->getHeaderLine('last-modified')));
        return $temporaryPath;
    }

    public function dumpFileContents(string $identifier): void
    {
        echo $this->client()->getContents(
            $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($identifier))
        );
    }

    private function client(): VercelBlobClient
    {
        if ($this->client instanceof VercelBlobClient) {
            return $this->client;
        }

        if ($this->token === '') {
            // 'token' is a manual-configuration escape hatch (apply-object-storage.php only writes tokenEnvName); the null path is required for BlobCredentials OIDC resolution.
            $this->token = BlobCredentials::resolveToken(
                $this->configuration['token'] ?? null,
                $this->tokenEnvName,
                true,
            ) ?? '';
        }
        if ($this->token === '') {
            throw new InvalidConfigurationException(
                'Vercel Blob storage requires BLOB_READ_WRITE_TOKEN or the per-request Vercel OIDC token.',
                1720100102
            );
        }

        $this->client = new VercelBlobClient(
            $this->storeId,
            $this->access,
            $this->token,
            $this->apiUrl
        );
        return $this->client;
    }

    private function listDirectoryEntries(string $folderIdentifier, bool $recursive, bool $includeFiles, bool $includeDirs): array
    {
        $folderKey = $this->keyFromFolderIdentifier($folderIdentifier);
        $entries = [];

        foreach ($this->client()->listPathnames($folderKey) as $object) {
            $key = $object['pathname'];
            if ($key === '' || $key === $folderKey) {
                continue;
            }

            if (str_ends_with($key, '/')) {
                if ($includeDirs && ($recursive || $this->isDirectChild($folderKey, $key))) {
                    $identifier = $this->identifierFromKey($key, true);
                    $entries[$identifier] = $this->directoryEntry(
                        $identifier,
                        'dir',
                        0,
                        $this->timestampFromLastModified($object['uploadedAt'])
                    );
                }
                continue;
            }

            $identifier = $this->identifierFromKey($key, false);
            if (!$recursive && !$this->isDirectChild($folderKey, $key)) {
                if ($includeDirs) {
                    $folder = $this->firstChildFolderIdentifier($folderKey, $key);
                    if ($folder !== null) {
                        $entries[$folder] = $this->directoryEntry($folder, 'dir', 0, time());
                    }
                }
                continue;
            }

            if ($recursive && $includeDirs) {
                $this->addAncestorFolders($entries, $identifier, $folderIdentifier);
            }
            if ($includeFiles) {
                $entries[$identifier] = $this->directoryEntry(
                    $identifier,
                    'file',
                    (int)$object['size'],
                    $this->timestampFromLastModified($object['uploadedAt'])
                );
            }
        }

        return $entries;
    }

    private function uploadLocalFile(string $localFilePath, string $fileIdentifier): void
    {
        if (!is_file($localFilePath)) {
            throw new FileDoesNotExistException('Local file "' . $localFilePath . '" does not exist.', 1784470719);
        }

        $this->client()->uploadFile(
            $this->keyFromFileIdentifier($fileIdentifier),
            $localFilePath,
            $this->detectContentType($fileIdentifier, $localFilePath),
            $this->cacheControlMaxAgeForIdentifier($fileIdentifier)
        );
    }

    private function putObject(string $fileIdentifier, string $contents): void
    {
        $this->client()->put(
            $this->keyFromFileIdentifier($fileIdentifier),
            $contents,
            $this->detectContentType($fileIdentifier),
            $this->cacheControlMaxAgeForIdentifier($fileIdentifier)
        );
    }

    private function copyKey(string $sourceKey, string $targetKey): void
    {
        $this->client()->copy($sourceKey, $targetKey);
    }

    private function putFolderPlaceholder(string $folderIdentifier): void
    {
        $this->client()->createFolder($this->keyFromFolderIdentifier($folderIdentifier));
    }

    private function listKeysWithLimit(string $prefix, ?int $limit): array
    {
        $keys = [];
        foreach ($this->client()->listPathnames($prefix, $limit) as $object) {
            $key = $object['pathname'];
            if ($key !== '') {
                $keys[] = $key;
            }
            if ($limit !== null && count($keys) >= $limit) {
                break;
            }
        }
        return $keys;
    }

    private function deleteKeys(array $keys): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            $this->client()->delete($chunk);
        }
    }

    /**
     * Raw Blob API head — hash() needs the etag/uploadedAt fingerprint fields
     * that the normalized headInfo() intentionally drops.
     *
     * @return array<string, mixed>|null
     */
    private function headObject(string $key): ?array
    {
        return $this->client()->head($key);
    }

    /**
     * @return array{size: int, mimetype: string, mtime: int}|null
     */
    private function headInfo(string $key): ?array
    {
        $head = $this->headObject($key);
        if ($head === null) {
            return null;
        }
        return [
            'size' => (int)($head['size'] ?? 0),
            'mimetype' => (string)($head['contentType'] ?? 'application/octet-stream'),
            'mtime' => $this->timestampFromLastModified($head['uploadedAt'] ?? null),
        ];
    }

    private function isDirectChild(string $folderKey, string $key): bool
    {
        if ($folderKey !== '' && !str_starts_with($key, $folderKey)) {
            return false;
        }

        $relative = trim(substr($key, strlen($folderKey)), '/');
        return $relative !== '' && !str_contains($relative, '/');
    }

    private function firstChildFolderIdentifier(string $folderKey, string $key): ?string
    {
        if ($folderKey !== '' && !str_starts_with($key, $folderKey)) {
            return null;
        }

        $relative = trim(substr($key, strlen($folderKey)), '/');
        if ($relative === '' || !str_contains($relative, '/')) {
            return null;
        }

        [$firstSegment] = explode('/', $relative, 2);
        return $this->identifierFromKey($folderKey . $firstSegment . '/', true);
    }

    private function cacheControlMaxAgeForIdentifier(string $fileIdentifier): ?int
    {
        // Prefix match on the first path segment so the cross-storage
        // "_processed_local_" folder (ADR-010) also gets the long-lived
        // policy; processed filenames embed a configuration checksum, so
        // aggressive caching is safe. A root-level file whose NAME merely
        // starts with the folder name stays on the default policy.
        $parts = explode('/', ltrim($fileIdentifier, '/'), 2);
        if (
            $this->processingFolder !== ''
            && isset($parts[1])
            && str_starts_with($parts[0], $this->processingFolder)
        ) {
            return $this->processedCacheControlMaxAge;
        }

        return $this->cacheControlMaxAge;
    }
}
