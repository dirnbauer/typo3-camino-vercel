<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\Resource\Driver;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mime\MimeTypes;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\SelfEmittableLazyOpenStream;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Driver\StreamableDriverInterface;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use Webconsulting\Typo3VercelBlobStorage\Client\VercelBlobClient;

/**
 * Vercel Blob FAL driver for stateless container runtimes.
 */
final class BlobDriver extends AbstractHierarchicalFilesystemDriver implements StreamableDriverInterface
{
    private string $storeId = '';
    private string $access = 'public';
    private string $tokenEnvName = 'BLOB_READ_WRITE_TOKEN';
    private string $token = '';
    private string $apiUrl = 'https://vercel.com/api/blob';
    private string $prefix = '';
    private ?string $publicBaseUrl = null;
    private string $defaultFolder = '/user_upload/';
    private ?int $cacheControlMaxAge = 31536000;
    private ?VercelBlobClient $client = null;

    /**
     * @var array<non-empty-string, FolderInterface::ROLE_*>
     */
    private array $mappingFolderNameToRole = [
        '_recycler_' => FolderInterface::ROLE_RECYCLER,
        '_temp_' => FolderInterface::ROLE_TEMPORARY,
        'user_upload' => FolderInterface::ROLE_USERUPLOAD,
    ];

    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_PUBLIC
            | Capabilities::CAPABILITY_WRITABLE
            | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS
        );
        $this->supportedHashAlgorithms = ['md5', 'sha1', 'sha256'];
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);
        return $this->capabilities;
    }

    public function processConfiguration(): void
    {
        $this->access = strtolower(trim((string)($this->configuration['access'] ?? 'public'))) === 'private' ? 'private' : 'public';
        $this->tokenEnvName = trim((string)($this->configuration['tokenEnvName'] ?? 'BLOB_READ_WRITE_TOKEN')) ?: 'BLOB_READ_WRITE_TOKEN';
        $this->token = $this->resolveToken();
        $this->storeId = $this->normalizeStoreId($this->configuration['storeId'] ?? null)
            ?? $this->normalizeStoreId(getenv('BLOB_STORE_ID') ?: null)
            ?? $this->parseStoreIdFromReadWriteToken($this->token);
        if ($this->storeId === '') {
            throw new InvalidConfigurationException(
                'Vercel Blob storage requires a store id. Set BLOB_STORE_ID or configure storeId.',
                1720100101
            );
        }

        $this->apiUrl = rtrim((string)($this->configuration['apiUrl'] ?? 'https://vercel.com/api/blob'), '/') ?: 'https://vercel.com/api/blob';
        $this->prefix = $this->normalizePrefix((string)($this->configuration['prefix'] ?? ''));
        $this->publicBaseUrl = $this->normalizePublicBaseUrl($this->configuration['publicBaseUrl'] ?? null);
        $cacheControlMaxAge = trim((string)($this->configuration['cacheControlMaxAge'] ?? '31536000'));
        $this->cacheControlMaxAge = $cacheControlMaxAge === '' ? null : max(0, (int)$cacheControlMaxAge);
        $this->defaultFolder = $this->canonicalizeAndCheckFolderIdentifier(
            '/' . trim((string)($this->configuration['defaultFolder'] ?? 'user_upload'), '/') . '/'
        );

        if ($this->access !== 'public') {
            $this->capabilities->removeCapability(Capabilities::CAPABILITY_PUBLIC);
        }
    }

    public function initialize(): void {}

    public function isCaseSensitiveFileSystem(): bool
    {
        if (array_key_exists('caseSensitive', $this->configuration)) {
            return $this->asBool($this->configuration['caseSensitive']);
        }
        return true;
    }

    public function getRootLevelFolder(): string
    {
        return '/';
    }

    public function getDefaultFolder(): string
    {
        if (!$this->folderExists($this->defaultFolder)) {
            $this->createFolder(trim($this->defaultFolder, '/'), '/', true);
        }
        return $this->defaultFolder;
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

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier === '' ? '/' : $parentFolderIdentifier);
        if (!$this->folderExists($parentFolderIdentifier)) {
            throw new FolderDoesNotExistException('Parent folder "' . $parentFolderIdentifier . '" does not exist.', 1720010002);
        }

        $newFolderName = trim($newFolderName, '/');
        $parts = GeneralUtility::trimExplode('/', $newFolderName, true);
        if ($parts === []) {
            throw new InvalidFileNameException('Folder name must not be empty.', 1720010003);
        }
        if (!$recursive && count($parts) > 1) {
            throw new InvalidFileNameException('Nested folder name requires recursive creation.', 1720010004);
        }

        $createdIdentifier = $parentFolderIdentifier;
        foreach ($parts as $part) {
            $createdIdentifier = $this->canonicalizeAndCheckFolderIdentifier($createdIdentifier . $this->sanitizeFileName($part) . '/');
            $this->putFolderPlaceholder($createdIdentifier);
        }

        return $createdIdentifier;
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $newIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->parentFolderIdentifierOfFolderIdentifier($folderIdentifier) . $this->sanitizeFileName($newName) . '/'
        );
        return $this->moveFolderObjects($folderIdentifier, $newIdentifier, true);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            throw new FileOperationErrorException('Deleting the root folder is not allowed.', 1720010005);
        }
        if (!$deleteRecursively && !$this->isFolderEmpty($folderIdentifier)) {
            throw new FileOperationErrorException('Folder "' . $folderIdentifier . '" is not empty.', 1720010006);
        }

        $keys = $this->listKeys($this->keyFromFolderIdentifier($folderIdentifier));
        if ($keys === []) {
            $keys[] = $this->keyFromFolderIdentifier($folderIdentifier);
        }
        $this->deleteKeys($keys);
        return true;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        if ($fileIdentifier === '/') {
            return false;
        }
        return $this->headObject($this->keyFromFileIdentifier($fileIdentifier)) !== null;
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return true;
        }

        $folderKey = $this->keyFromFolderIdentifier($folderIdentifier);
        if ($this->headObject($folderKey) !== null) {
            return true;
        }

        return $this->listKeys($folderKey, 1) !== [];
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderKey = $this->keyFromFolderIdentifier($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier));
        foreach ($this->listKeys($folderKey, 2) as $key) {
            if ($key !== $folderKey) {
                return false;
            }
        }
        return true;
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        $localFilePath = $this->canonicalizeAndCheckFilePath($localFilePath);
        $newFileName = $this->sanitizeFileName($newFileName !== '' ? $newFileName : PathUtility::basename($localFilePath));
        $newIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $newFileName
        );
        $this->uploadLocalFile($localFilePath, $newIdentifier);

        if ($removeOriginal) {
            @unlink($localFilePath);
        }

        return $newIdentifier;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier) . $this->sanitizeFileName(ltrim($fileName, '/'))
        );
        $this->putObject($fileIdentifier, '');
        return $fileIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $this->sanitizeFileName($fileName)
        );
        $this->copyObject($fileIdentifier, $targetIdentifier);
        return $targetIdentifier;
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            $this->getParentFolderIdentifierOfIdentifier($fileIdentifier) . $this->sanitizeFileName($newName)
        );
        if ($this->fileExists($newIdentifier)) {
            throw new ExistingTargetFileNameException('The target file "' . $newIdentifier . '" already exists.', 1720010007);
        }
        $this->copyObject($fileIdentifier, $newIdentifier);
        $this->deleteFile($fileIdentifier);
        return $newIdentifier;
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        $this->uploadLocalFile($localFilePath, $this->canonicalizeAndCheckFileIdentifier($fileIdentifier));
        @unlink($localFilePath);
        return true;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $this->client()->delete([
            $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($fileIdentifier)),
        ]);
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

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        $targetIdentifier = $this->copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName);
        $this->deleteFile($fileIdentifier);
        return $targetIdentifier;
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        $newFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $this->sanitizeFileName($newFolderName) . '/'
        );
        return $this->moveFolderObjects($this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier), $newFolderIdentifier, true);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        $newFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $this->sanitizeFileName($newFolderName) . '/'
        );
        $this->moveFolderObjects($this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier), $newFolderIdentifier, false);
        return true;
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

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $this->putObject($this->canonicalizeAndCheckFileIdentifier($fileIdentifier), $contents);
        return strlen($contents);
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        return $this->fileExists($this->getFileInFolder($fileName, $folderIdentifier));
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        return $this->folderExists($this->getFolderInFolder($folderName, $folderIdentifier));
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
        $response = $this->client()->downloadToFile($this->keyFromFileIdentifier($fileIdentifier), $temporaryPath);
        @touch($temporaryPath, $this->timestampFromLastModified($response->getHeaderLine('last-modified')));
        return $temporaryPath;
    }

    public function getPermissions(string $identifier): array
    {
        return [
            'r' => true,
            'w' => true,
        ];
    }

    public function dumpFileContents(string $identifier): void
    {
        echo $this->client()->getContents(
            $this->keyFromFileIdentifier($this->canonicalizeAndCheckFileIdentifier($identifier))
        );
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $entryIdentifier = str_ends_with($identifier, '/')
            ? $this->canonicalizeAndCheckFolderIdentifier($identifier)
            : $this->canonicalizeAndCheckFileIdentifier($identifier);

        if ($folderIdentifier === '/' || $folderIdentifier === $entryIdentifier) {
            return true;
        }
        if ($entryIdentifier === rtrim($folderIdentifier, '/')) {
            return true;
        }
        return str_starts_with($entryIdentifier, $folderIdentifier);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $head = $this->headObject($this->keyFromFileIdentifier($fileIdentifier));
        if ($head === null) {
            throw new FileDoesNotExistException('File "' . $fileIdentifier . '" does not exist.', 1720010009);
        }

        if ($propertiesToExtract === []) {
            $propertiesToExtract = [
                'size',
                'atime',
                'mtime',
                'ctime',
                'mimetype',
                'name',
                'extension',
                'identifier',
                'identifier_hash',
                'storage',
                'folder_hash',
            ];
        }

        $mtime = $this->timestampFromLastModified($head['uploadedAt'] ?? null);
        $values = [
            'size' => (int)($head['size'] ?? 0),
            'atime' => $mtime,
            'mtime' => $mtime,
            'ctime' => $mtime,
            'mimetype' => (string)($head['contentType'] ?? 'application/octet-stream'),
            'name' => PathUtility::basename($fileIdentifier),
            'extension' => PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION),
            'identifier' => $fileIdentifier,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'storage' => $this->storageUid,
            'folder_hash' => $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($fileIdentifier)),
        ];

        return array_intersect_key($values, array_flip($propertiesToExtract));
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if (!$this->folderExists($folderIdentifier)) {
            throw new FolderDoesNotExistException('Folder "' . $folderIdentifier . '" does not exist.', 1720010010);
        }

        $mtime = time();
        $head = $folderIdentifier === '/' ? null : $this->headObject($this->keyFromFolderIdentifier($folderIdentifier));
        if ($head !== null) {
            $mtime = $this->timestampFromLastModified($head['uploadedAt'] ?? null);
        }

        return [
            'identifier' => $folderIdentifier,
            'name' => PathUtility::basename(rtrim($folderIdentifier, '/')),
            'mtime' => $mtime,
            'ctime' => $mtime,
            'storage' => (int)$this->storageUid,
        ];
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFileIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . ltrim($fileName, '/')
        );
    }

    public function getFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $filenameFilterCallbacks, true, false, $recursive, $sort, $sortRev);
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier(
            $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . trim($folderName, '/') . '/'
        );
    }

    public function getFoldersInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $folderNameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false
    ): array {
        return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $folderNameFilterCallbacks, false, true, $recursive, $sort, $sortRev);
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        return count($this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks));
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks));
    }

    public function sanitizeFileName(string $fileName): string
    {
        $fileName = class_exists(\Normalizer::class) ? (\Normalizer::normalize($fileName) ?: $fileName) : $fileName;
        if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['UTF8filesystem'] ?? true) {
            $cleanFileName = (string)preg_replace('/[' . LocalDriver::UNSAFE_FILENAME_CHARACTER_EXPRESSION . ']/u', '_', trim($fileName));
            if (!$this->isCaseSensitiveFileSystem()) {
                $cleanFileName = mb_strtolower($cleanFileName, 'utf-8');
            }
        } else {
            $fileName = GeneralUtility::makeInstance(CharsetConverter::class)->utf8_char_mapping($fileName);
            $cleanFileName = (string)preg_replace('/[' . LocalDriver::UNSAFE_FILENAME_CHARACTER_EXPRESSION . '\\xC0-\\xFF]/', '_', trim($fileName));
            if (!$this->isCaseSensitiveFileSystem()) {
                $cleanFileName = strtolower($cleanFileName);
            }
        }
        $cleanFileName = rtrim($cleanFileName, '.');
        if ($cleanFileName === '') {
            throw new InvalidFileNameException('File name ' . $fileName . ' is invalid.', 1720010011);
        }
        return $cleanFileName;
    }

    public function streamFile(string $identifier, array $properties): ResponseInterface
    {
        $fileInfo = $this->getFileInfoByIdentifier($identifier, ['name', 'mimetype', 'mtime', 'size']);
        $downloadName = (string)($properties['filename_overwrite'] ?? $fileInfo['name'] ?? '');
        $mimeType = (string)($properties['mimetype_overwrite'] ?? $fileInfo['mimetype'] ?? 'application/octet-stream');
        $contentDisposition = ($properties['as_download'] ?? false) ? 'attachment' : 'inline';
        $temporaryPath = $this->getFileForLocalProcessing($identifier, false);

        return new Response(
            new SelfEmittableLazyOpenStream($temporaryPath),
            200,
            [
                'Content-Disposition' => $contentDisposition . '; filename="' . $downloadName . '"',
                'Content-Type' => $mimeType,
                'Content-Length' => (string)$fileInfo['size'],
                'Last-Modified' => gmdate('D, d M Y H:i:s', (int)$fileInfo['mtime']) . ' GMT',
                'Cache-Control' => '',
            ]
        );
    }

    public function getRole(string $folderIdentifier): string
    {
        $name = PathUtility::basename(rtrim($folderIdentifier, '/'));
        return $this->mappingFolderNameToRole[$name] ?? FolderInterface::ROLE_DEFAULT;
    }

    private function client(): VercelBlobClient
    {
        if ($this->client instanceof VercelBlobClient) {
            return $this->client;
        }

        $this->client = new VercelBlobClient(
            $this->storeId,
            $this->access,
            $this->token,
            $this->apiUrl
        );
        return $this->client;
    }

    private function getDirectoryItemList(
        string $folderIdentifier,
        int $start,
        int $numberOfItems,
        array $filterMethods,
        bool $includeFiles,
        bool $includeDirs,
        bool $recursive,
        string $sort,
        bool $sortRev
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if (!$this->folderExists($folderIdentifier)) {
            throw new FolderDoesNotExistException('Folder "' . $folderIdentifier . '" does not exist.', 1720010012);
        }

        $items = $this->sortDirectoryEntries(
            $this->listDirectoryEntries($folderIdentifier, $recursive, $includeFiles, $includeDirs),
            $sort,
            $sortRev
        );
        $result = [];
        $remaining = $numberOfItems > 0 ? $numberOfItems : -1;
        foreach ($items as $item) {
            if (!$this->applyFilterMethodsToDirectoryItem(
                $filterMethods,
                $item['name'],
                $item['identifier'],
                $item['type'] === 'dir' ? $this->parentFolderIdentifierOfFolderIdentifier($item['identifier']) : $this->getParentFolderIdentifierOfIdentifier($item['identifier'])
            )) {
                continue;
            }
            if ($start > 0) {
                $start--;
                continue;
            }
            if ($numberOfItems > 0 && $remaining <= 0) {
                break;
            }
            $result[$item['identifier']] = $item['identifier'];
            if ($remaining > 0) {
                $remaining--;
            }
        }
        return $result;
    }

    private function applyFilterMethodsToDirectoryItem(array $filterMethods, string $itemName, string $itemIdentifier, string $parentIdentifier): bool
    {
        foreach ($filterMethods as $filter) {
            if (!is_callable($filter)) {
                continue;
            }
            $result = $filter($itemName, $itemIdentifier, $parentIdentifier, [], $this);
            if ($result === -1) {
                return false;
            }
            if ($result === false) {
                throw new \RuntimeException('Could not apply file/folder name filter.', 1720010013);
            }
        }
        return true;
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

    private function addAncestorFolders(array &$entries, string $fileIdentifier, string $rootFolderIdentifier): void
    {
        $parent = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
        $folders = [];
        while ($parent !== '/' && $parent !== $rootFolderIdentifier) {
            $folders[] = $parent;
            $parent = $this->parentFolderIdentifierOfFolderIdentifier($parent);
        }
        foreach (array_reverse($folders) as $folderIdentifier) {
            $entries[$folderIdentifier] = $this->directoryEntry($folderIdentifier, 'dir', 0, time());
        }
    }

    private function directoryEntry(string $identifier, string $type, int $size, int $tstamp): array
    {
        return [
            'identifier' => $identifier,
            'name' => PathUtility::basename(rtrim($identifier, '/')),
            'type' => $type,
            'size' => $size,
            'tstamp' => $tstamp,
        ];
    }

    private function sortDirectoryEntries(array $entries, string $sort, bool $sortRev): array
    {
        $sortMultiplier = $sortRev ? -1 : 1;
        uasort($entries, static function (array $left, array $right) use ($sort, $sortMultiplier): int {
            $leftValue = match ($sort) {
                'size' => (string)$left['size'] . 's',
                'tstamp', 'crdate' => (string)$left['tstamp'] . 't',
                'fileext' => PathUtility::pathinfo($left['name'], PATHINFO_EXTENSION),
                'rw' => 'RW',
                default => $left['name'],
            };
            $rightValue = match ($sort) {
                'size' => (string)$right['size'] . 's',
                'tstamp', 'crdate' => (string)$right['tstamp'] . 't',
                'fileext' => PathUtility::pathinfo($right['name'], PATHINFO_EXTENSION),
                'rw' => 'RW',
                default => $right['name'],
            };
            return strnatcasecmp($leftValue, $rightValue) * $sortMultiplier;
        });
        return $entries;
    }

    private function uploadLocalFile(string $localFilePath, string $fileIdentifier): void
    {
        if (!is_file($localFilePath)) {
            throw new FileDoesNotExistException('Local file "' . $localFilePath . '" does not exist.', 1720010014);
        }

        $this->client()->uploadFile(
            $this->keyFromFileIdentifier($fileIdentifier),
            $localFilePath,
            $this->detectContentType($fileIdentifier, $localFilePath),
            $this->cacheControlMaxAge
        );
    }

    private function putObject(string $fileIdentifier, string $contents): void
    {
        $this->client()->put(
            $this->keyFromFileIdentifier($fileIdentifier),
            $contents,
            $this->detectContentType($fileIdentifier),
            $this->cacheControlMaxAge
        );
    }

    private function copyObject(string $sourceIdentifier, string $targetIdentifier): void
    {
        $this->client()->copy(
            $this->keyFromFileIdentifier($sourceIdentifier),
            $this->keyFromFileIdentifier($targetIdentifier)
        );
    }

    private function putFolderPlaceholder(string $folderIdentifier): void
    {
        $this->client()->createFolder($this->keyFromFolderIdentifier($folderIdentifier));
    }

    private function moveFolderObjects(string $sourceFolderIdentifier, string $targetFolderIdentifier, bool $deleteSource): array
    {
        if ($sourceFolderIdentifier === '/') {
            throw new FileOperationErrorException('Moving or copying the root folder is not allowed.', 1720010015);
        }
        if (!$this->folderExists($sourceFolderIdentifier)) {
            throw new FolderDoesNotExistException('Folder "' . $sourceFolderIdentifier . '" does not exist.', 1720010016);
        }

        $sourceKey = $this->keyFromFolderIdentifier($sourceFolderIdentifier);
        $targetKey = $this->keyFromFolderIdentifier($targetFolderIdentifier);
        $keys = $this->listKeys($sourceKey);
        if ($keys === []) {
            $keys[] = $sourceKey;
        }

        $map = [$sourceFolderIdentifier => $targetFolderIdentifier];
        foreach ($keys as $key) {
            $suffix = substr($key, strlen($sourceKey));
            $newKey = $targetKey . $suffix;
            $this->client()->copy($key, $newKey);

            if ($key !== $sourceKey) {
                $map[$this->identifierFromKey($key, str_ends_with($key, '/'))] = $this->identifierFromKey($newKey, str_ends_with($newKey, '/'));
            }
        }

        if ($deleteSource) {
            $this->deleteKeys($keys);
        }

        return $map;
    }

    private function listKeys(string $prefix, ?int $limit = null): array
    {
        $keys = [];
        foreach ($this->client()->listPathnames($prefix) as $object) {
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
     * @return array<string, mixed>|null
     */
    private function headObject(string $key): ?array
    {
        return $this->client()->head($key);
    }

    private function keyFromFileIdentifier(string $identifier): string
    {
        $identifier = ltrim($this->canonicalizeAndCheckFileIdentifier($identifier), '/');
        return $this->prefix . $identifier;
    }

    private function keyFromFolderIdentifier(string $identifier): string
    {
        $identifier = trim($this->canonicalizeAndCheckFolderIdentifier($identifier), '/');
        if ($identifier === '') {
            return $this->prefix;
        }
        return $this->prefix . $identifier . '/';
    }

    private function identifierFromKey(string $key, bool $isFolder): string
    {
        if ($this->prefix !== '' && str_starts_with($key, $this->prefix)) {
            $key = substr($key, strlen($this->prefix));
        }
        $key = ltrim($key, '/');
        if ($key === '') {
            return '/';
        }

        $identifier = '/' . $key;
        return $isFolder ? rtrim($identifier, '/') . '/' : $this->canonicalizeAndCheckFileIdentifier($identifier);
    }

    private function detectContentType(string $fileIdentifier, ?string $localFilePath = null): string
    {
        if ($localFilePath !== null && is_file($localFilePath)) {
            $detectedType = mime_content_type($localFilePath);
            if (is_string($detectedType) && $detectedType !== '' && $detectedType !== 'application/octet-stream') {
                return $detectedType;
            }
        }

        $extension = strtolower((string)PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION));
        if ($extension !== '') {
            $mimeTypes = MimeTypes::getDefault()->getMimeTypes($extension);
            if ($mimeTypes !== []) {
                return $mimeTypes[0];
            }
        }

        return 'application/octet-stream';
    }

    private function encodeKeyForUrl(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($key, '/'))));
    }

    private function parentFolderIdentifierOfFolderIdentifier(string $folderIdentifier): string
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return '/';
        }
        $parent = PathUtility::dirname(rtrim($folderIdentifier, '/'));
        if ($parent === '/' || $parent === '.') {
            return '/';
        }
        return rtrim($parent, '/') . '/';
    }

    private function timestampFromLastModified(mixed $lastModified): int
    {
        if ($lastModified instanceof \DateTimeInterface) {
            return $lastModified->getTimestamp();
        }
        if (is_string($lastModified) && $lastModified !== '') {
            $timestamp = strtotime($lastModified);
            return $timestamp === false ? time() : $timestamp;
        }
        return time();
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

    private function resolveToken(): string
    {
        $configuredToken = $this->normalizeNullableString($this->configuration['token'] ?? null);
        if ($configuredToken !== null) {
            return $configuredToken;
        }

        foreach ([$this->tokenEnvName, 'BLOB_READ_WRITE_TOKEN', 'VERCEL_OIDC_TOKEN'] as $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw new InvalidConfigurationException(
            'Vercel Blob storage requires BLOB_READ_WRITE_TOKEN or VERCEL_OIDC_TOKEN.',
            1720100102
        );
    }

    private function normalizeStoreId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, 'store_') ? substr($value, 6) : $value;
    }

    private function parseStoreIdFromReadWriteToken(string $token): string
    {
        $parts = explode('_', $token);
        return $this->normalizeStoreId($parts[3] ?? '') ?? '';
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, "/ \t\n\r\0\x0B");
        return $prefix === '' ? '' : $prefix . '/';
    }

    private function normalizePublicBaseUrl(mixed $value): ?string
    {
        $value = $this->normalizeNullableString($value);
        return $value === null ? null : rtrim($value, '/') . '/';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
