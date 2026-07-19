<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Resource\Driver;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\StreamableDriverInterface;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use Webconsulting\Typo3ObjectStorageCore\Resource\Driver\ObjectStorageDriverTrait;

/**
 * S3-compatible FAL driver for stateless container runtimes.
 */
final class S3Driver extends AbstractHierarchicalFilesystemDriver implements StreamableDriverInterface
{
    use ObjectStorageDriverTrait;

    private string $bucket = '';
    private string $region = 'auto';
    private ?string $endpoint = null;
    private ?string $accessKey = null;
    private ?string $secretKey = null;
    private int $signedUrlTtl = 0;
    private bool $pathStyleEndpoint = true;
    private ?string $cacheControl = 'public, max-age=31536000, immutable';
    private ?S3Client $client = null;

    public function processConfiguration(): void
    {
        $this->bucket = trim((string)($this->configuration['bucket'] ?? ''));
        if ($this->bucket === '') {
            throw new InvalidConfigurationException('S3-compatible storage requires a bucket name.', 1720010001);
        }

        $this->region = trim((string)($this->configuration['region'] ?? 'auto')) ?: 'auto';
        $this->endpoint = $this->normalizeNullableString($this->configuration['endpoint'] ?? null);
        $this->accessKey = $this->normalizeNullableString($this->configuration['accessKey'] ?? null);
        $this->secretKey = $this->normalizeNullableString($this->configuration['secretKey'] ?? null);
        $this->prefix = $this->normalizePrefix((string)($this->configuration['prefix'] ?? ''));
        $this->publicBaseUrl = $this->normalizePublicBaseUrl($this->configuration['publicBaseUrl'] ?? null);
        $this->signedUrlTtl = max(0, (int)($this->configuration['signedUrlTtl'] ?? 0));
        $this->pathStyleEndpoint = $this->asBool($this->configuration['pathStyleEndpoint'] ?? true);
        $this->cacheControl = $this->normalizeNullableString($this->configuration['cacheControl'] ?? null);
        $this->defaultFolder = $this->canonicalizeAndCheckFolderIdentifier(
            '/' . trim((string)($this->configuration['defaultFolder'] ?? 'user_upload'), '/') . '/'
        );

        if ($this->publicBaseUrl === null && $this->signedUrlTtl === 0) {
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

        if ($this->signedUrlTtl > 0) {
            $command = $this->client()->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return (string)$this->client()->createPresignedRequest($command, '+' . $this->signedUrlTtl . ' seconds')->getUri();
        }

        return null;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $key = $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($fileIdentifier));
        $this->client()->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $this->invalidateStatCache($key);
        return true;
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        $hash = hash_file($hashAlgorithm, $this->getFileForLocalProcessing($fileIdentifier, false));
        if ($hash === false) {
            throw new FileOperationErrorException('Could not hash file "' . $fileIdentifier . '".', 1720010008);
        }
        return $hash;
    }

    public function getFileContents(string $fileIdentifier): string
    {
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($fileIdentifier)),
            ]);
        } catch (AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return '';
            }
            throw $exception;
        }

        return (string)$result['Body'];
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
        $result = $this->client()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->keyFromFileIdentifier($fileIdentifier),
            'SaveAs' => $temporaryPath,
        ]);
        @touch($temporaryPath, $this->timestampFromLastModified($result['LastModified'] ?? null));
        return $temporaryPath;
    }

    public function dumpFileContents(string $identifier): void
    {
        $result = $this->client()->getObject([
            'Bucket' => $this->bucket,
            'Key' => $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($identifier)),
        ]);
        $body = $result['Body'];
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }

    private function client(): S3Client
    {
        if ($this->client instanceof S3Client) {
            return $this->client;
        }

        $options = [
            'version' => 'latest',
            'region' => $this->region,
            'use_path_style_endpoint' => $this->pathStyleEndpoint,
        ];
        if ($this->endpoint !== null) {
            $options['endpoint'] = $this->endpoint;
        }
        if ($this->accessKey !== null && $this->secretKey !== null) {
            $options['credentials'] = [
                'key' => $this->accessKey,
                'secret' => $this->secretKey,
            ];
        }

        $this->client = new S3Client($options);
        return $this->client;
    }

    private function listDirectoryEntries(string $folderIdentifier, bool $recursive, bool $includeFiles, bool $includeDirs): array
    {
        $folderKey = $this->keyFromFolderIdentifier($folderIdentifier);
        $entries = [];
        $token = null;
        do {
            $args = [
                'Bucket' => $this->bucket,
                'Prefix' => $folderKey,
            ];
            if (!$recursive) {
                $args['Delimiter'] = '/';
            }
            if ($token !== null) {
                $args['ContinuationToken'] = $token;
            }

            $result = $this->client()->listObjectsV2($args);

            if ($includeDirs) {
                foreach (($result['CommonPrefixes'] ?? []) as $prefix) {
                    $key = (string)($prefix['Prefix'] ?? '');
                    if ($key === '' || $key === $folderKey) {
                        continue;
                    }
                    $identifier = $this->identifierFromKey($key, true);
                    $entries[$identifier] = $this->directoryEntry($identifier, 'dir', 0, time());
                }
            }

            foreach (($result['Contents'] ?? []) as $object) {
                $key = (string)($object['Key'] ?? '');
                if ($key === '' || $key === $folderKey) {
                    continue;
                }
                if (str_ends_with($key, '/')) {
                    if ($includeDirs && $recursive) {
                        $identifier = $this->identifierFromKey($key, true);
                        $entries[$identifier] = $this->directoryEntry($identifier, 'dir', 0, $this->timestampFromLastModified($object['LastModified'] ?? null));
                    }
                    continue;
                }

                $identifier = $this->identifierFromKey($key, false);
                if ($recursive && $includeDirs) {
                    $this->addAncestorFolders($entries, $identifier, $folderIdentifier);
                }
                if ($includeFiles) {
                    $entries[$identifier] = $this->directoryEntry(
                        $identifier,
                        'file',
                        (int)($object['Size'] ?? 0),
                        $this->timestampFromLastModified($object['LastModified'] ?? null)
                    );
                }
            }

            $token = isset($result['NextContinuationToken']) ? (string)$result['NextContinuationToken'] : null;
        } while ($token !== null);

        return $entries;
    }

    private function uploadLocalFile(string $localFilePath, string $fileIdentifier): void
    {
        if (!is_file($localFilePath)) {
            throw new FileDoesNotExistException('Local file "' . $localFilePath . '" does not exist.', 1720010014);
        }

        $args = [
            'Bucket' => $this->bucket,
            'Key' => $this->keyFromFileIdentifier($fileIdentifier),
            'SourceFile' => $localFilePath,
            'ContentType' => $this->detectContentType($fileIdentifier, $localFilePath),
        ];
        if ($this->cacheControl !== null) {
            $args['CacheControl'] = $this->cacheControl;
        }
        $this->client()->putObject($args);
    }

    private function putObject(string $fileIdentifier, string $contents): void
    {
        $args = [
            'Bucket' => $this->bucket,
            'Key' => $this->keyFromFileIdentifier($fileIdentifier),
            'Body' => $contents,
            'ContentType' => $this->detectContentType($fileIdentifier),
        ];
        if ($this->cacheControl !== null) {
            $args['CacheControl'] = $this->cacheControl;
        }
        $this->client()->putObject($args);
    }

    private function copyKey(string $sourceKey, string $targetKey): void
    {
        $this->client()->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => $this->copySource($sourceKey),
            'Key' => $targetKey,
        ]);
    }

    private function putFolderPlaceholder(string $folderIdentifier): void
    {
        $this->client()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->keyFromFolderIdentifier($folderIdentifier),
            'Body' => '',
            'ContentType' => 'application/x-directory',
        ]);
    }

    private function listKeysWithLimit(string $prefix, ?int $limit): array
    {
        $keys = [];
        $token = null;
        do {
            $args = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ];
            if ($limit !== null) {
                $args['MaxKeys'] = max(1, min(1000, $limit - count($keys)));
            }
            if ($token !== null) {
                $args['ContinuationToken'] = $token;
            }
            $result = $this->client()->listObjectsV2($args);
            foreach (($result['Contents'] ?? []) as $object) {
                $key = (string)($object['Key'] ?? '');
                if ($key !== '') {
                    $keys[] = $key;
                }
                if ($limit !== null && count($keys) >= $limit) {
                    return $keys;
                }
            }
            $token = isset($result['NextContinuationToken']) ? (string)$result['NextContinuationToken'] : null;
        } while ($token !== null);
        return $keys;
    }

    private function deleteKeys(array $keys): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            $this->client()->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => [
                    'Objects' => array_map(static fn (string $key): array => ['Key' => $key], $chunk),
                    'Quiet' => true,
                ],
            ]);
        }
    }

    private function headObject(string $key): ?Result
    {
        try {
            return $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }
            throw $exception;
        }
    }

    /**
     * Thin adapter from the raw Aws\Result to the normalized stat array the
     * shared trait consumes and caches.
     *
     * @return array{size: int, mimetype: string, mtime: int}|null
     */
    private function headInfo(string $key): ?array
    {
        $result = $this->headObject($key);
        if ($result === null) {
            return null;
        }
        return [
            'size' => (int)($result['ContentLength'] ?? 0),
            'mimetype' => (string)($result['ContentType'] ?? 'application/octet-stream'),
            'mtime' => $this->timestampFromLastModified($result['LastModified'] ?? null),
        ];
    }

    private function isNotFound(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound', '404'], true);
    }

    private function copySource(string $key): string
    {
        return $this->bucket . '/' . $this->encodeKeyForUrl($key);
    }
}
