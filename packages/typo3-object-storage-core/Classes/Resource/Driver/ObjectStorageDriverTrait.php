<?php

declare(strict_types=1);

namespace Webconsulting\Typo3ObjectStorageCore\Resource\Driver;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mime\MimeTypes;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\SelfEmittableLazyOpenStream;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Behavior shared by the flat-namespace object storage FAL drivers
 * (Vercel Blob, S3-compatible).
 *
 * The backend specifics stay in the using driver behind the abstract
 * primitives declared at the bottom: raw head/list/put/copy/delete calls and
 * directory listing pagination. The drivers remain final, which is why this
 * is a trait and not an abstract base class.
 *
 * Existence and stat lookups run through a two-layer cache (see
 * headInfoCached()) because TYPO3 fires several exists() checks per image per
 * uncached render, each of which would otherwise be a synchronous HTTPS
 * round-trip to the storage backend.
 *
 * @phpstan-require-extends AbstractHierarchicalFilesystemDriver
 */
trait ObjectStorageDriverTrait
{
    /**
     * Cross-request lifetime for positive stat entries in the 'hash' cache.
     * A stale positive is bounded by this TTL; negatives are never stored
     * cross-request because a stale "missing" would trigger reprocessing.
     */
    private const STAT_CACHE_LIFETIME = 900;

    private string $prefix = '';
    private ?string $publicBaseUrl = null;
    private string $defaultFolder = '/user_upload/';

    /**
     * Layer 1: per-request stat cache, object key => normalized head info or
     * null when the object is known to be missing (negatives allowed here).
     *
     * @var array<string, array{size: int, mimetype: string, mtime: int}|null>
     */
    private array $headInfoRequestCache = [];

    /**
     * Layer 1 companion for folder existence, folder key => bool. Folders
     * without a placeholder object exist only through their children, so the
     * result of the list probe is memoized per request as well.
     *
     * @var array<string, bool>
     */
    private array $folderExistsRequestCache = [];

    /** Layer 2: TYPO3 'hash' cache (Redis in production), positives only. */
    private ?FrontendInterface $statCache = null;
    private bool $statCacheResolved = false;

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
        $createdKeys = [];
        foreach ($parts as $part) {
            $createdIdentifier = $this->canonicalizeAndCheckFolderIdentifier($createdIdentifier . $this->sanitizeFileName($part) . '/');
            $this->putFolderPlaceholder($createdIdentifier);
            $createdKeys[] = $this->keyFromFolderIdentifier($createdIdentifier);
        }
        $this->invalidateStatCache(...$createdKeys);

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

        $keys = $this->listKeysWithLimit($this->keyFromFolderIdentifier($folderIdentifier), null);
        if ($keys === []) {
            $keys[] = $this->keyFromFolderIdentifier($folderIdentifier);
        }
        $this->deleteKeys($keys);
        $this->invalidateStatCache(...$keys);
        return true;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        if ($fileIdentifier === '/') {
            return false;
        }
        return $this->headInfoCached($this->keyFromFileIdentifier($fileIdentifier)) !== null;
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return true;
        }

        $folderKey = $this->keyFromFolderIdentifier($folderIdentifier);
        if (array_key_exists($folderKey, $this->folderExistsRequestCache)) {
            return $this->folderExistsRequestCache[$folderKey];
        }

        $exists = $this->headInfoCached($folderKey) !== null
            || $this->listKeysWithLimit($folderKey, 1) !== [];
        return $this->folderExistsRequestCache[$folderKey] = $exists;
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderKey = $this->keyFromFolderIdentifier($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier));
        foreach ($this->listKeysWithLimit($folderKey, 2) as $key) {
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
        $this->invalidateStatCache($this->keyFromFileIdentifier($newIdentifier));

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
        $this->invalidateStatCache($this->keyFromFileIdentifier($fileIdentifier));
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
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->uploadLocalFile($localFilePath, $fileIdentifier);
        $this->invalidateStatCache($this->keyFromFileIdentifier($fileIdentifier));
        @unlink($localFilePath);
        return true;
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

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->putObject($fileIdentifier, $contents);
        $this->invalidateStatCache($this->keyFromFileIdentifier($fileIdentifier));
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

    public function getPermissions(string $identifier): array
    {
        return [
            'r' => true,
            'w' => true,
        ];
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
        $head = $this->headInfoCached($this->keyFromFileIdentifier($fileIdentifier));
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

        $mtime = $head['mtime'];
        $values = [
            'size' => $head['size'],
            'atime' => $mtime,
            'mtime' => $mtime,
            'ctime' => $mtime,
            'mimetype' => $head['mimetype'],
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
        $head = $folderIdentifier === '/' ? null : $this->headInfoCached($this->keyFromFolderIdentifier($folderIdentifier));
        if ($head !== null) {
            $mtime = $head['mtime'];
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

    private function copyObject(string $sourceIdentifier, string $targetIdentifier): void
    {
        $targetKey = $this->keyFromFileIdentifier($targetIdentifier);
        $this->copyKey($this->keyFromFileIdentifier($sourceIdentifier), $targetKey);
        $this->invalidateStatCache($targetKey);
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
        $keys = $this->listKeysWithLimit($sourceKey, null);
        if ($keys === []) {
            $keys[] = $sourceKey;
        }

        $map = [$sourceFolderIdentifier => $targetFolderIdentifier];
        $touchedKeys = $keys;
        foreach ($keys as $key) {
            $suffix = substr($key, strlen($sourceKey));
            $newKey = $targetKey . $suffix;
            $this->copyKey($key, $newKey);
            $touchedKeys[] = $newKey;

            if ($key !== $sourceKey) {
                $map[$this->identifierFromKey($key, str_ends_with($key, '/'))] = $this->identifierFromKey($newKey, str_ends_with($newKey, '/'));
            }
        }

        if ($deleteSource) {
            $this->deleteKeys($keys);
        }
        $this->invalidateStatCache(...$touchedKeys);

        return $map;
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

    /**
     * Two-layer stat lookup: the per-request array short-circuits repeated
     * exists()/stat calls within one request (positives and negatives), the
     * shared 'hash' cache carries positive head info across requests so a warm
     * entry avoids the HEAD round-trip entirely.
     *
     * @return array{size: int, mimetype: string, mtime: int}|null
     */
    private function headInfoCached(string $key): ?array
    {
        if (array_key_exists($key, $this->headInfoRequestCache)) {
            return $this->headInfoRequestCache[$key];
        }

        $cache = $this->statCacheBackend();
        $cacheIdentifier = $this->statCacheIdentifier($key);
        if ($cache !== null) {
            $cached = $cache->get($cacheIdentifier);
            if (is_array($cached)) {
                return $this->headInfoRequestCache[$key] = $cached;
            }
        }

        $info = $this->headInfo($key);
        $this->headInfoRequestCache[$key] = $info;
        if ($info !== null && $cache !== null) {
            $cache->set($cacheIdentifier, $info, [], self::STAT_CACHE_LIFETIME);
        }
        return $info;
    }

    /**
     * Drops the entire per-request cache (cheap, always correct) and removes
     * the given keys from the shared cache. Folder-wide operations pass every
     * enumerated key; anything not enumerable is bounded by the TTL.
     */
    private function invalidateStatCache(string ...$keys): void
    {
        $this->headInfoRequestCache = [];
        $this->folderExistsRequestCache = [];

        if ($keys === []) {
            return;
        }
        $cache = $this->statCacheBackend();
        if ($cache === null) {
            return;
        }
        foreach ($keys as $key) {
            $cache->remove($this->statCacheIdentifier($key));
        }
    }

    private function statCacheIdentifier(string $key): string
    {
        return 'objstat_' . sha1(static::class . '|' . (string)$this->storageUid . '|' . $key);
    }

    private function statCacheBackend(): ?FrontendInterface
    {
        if (!$this->statCacheResolved) {
            $this->statCacheResolved = true;
            try {
                $this->statCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('hash');
            } catch (\Throwable) {
                // Install tool or early boot: no cache manager yet — degrade
                // silently to the per-request layer.
                $this->statCache = null;
            }
        }
        return $this->statCache;
    }

    /**
     * Normalized single-object stat: null when the object does not exist.
     * The result must be plain scalars so it can live in the shared cache.
     *
     * @return array{size: int, mimetype: string, mtime: int}|null
     */
    abstract private function headInfo(string $key): ?array;

    /**
     * All object keys under the given prefix, up to $limit (null = all).
     *
     * @return array<int, string>
     */
    abstract private function listKeysWithLimit(string $prefix, ?int $limit): array;

    /**
     * @param array<int, string> $keys
     */
    abstract private function deleteKeys(array $keys): void;

    abstract private function copyKey(string $sourceKey, string $targetKey): void;

    abstract private function putObject(string $fileIdentifier, string $contents): void;

    abstract private function uploadLocalFile(string $localFilePath, string $fileIdentifier): void;

    abstract private function putFolderPlaceholder(string $folderIdentifier): void;

    /**
     * Directory listing including backend pagination, as directoryEntry() rows
     * keyed by identifier.
     */
    abstract private function listDirectoryEntries(string $folderIdentifier, bool $recursive, bool $includeFiles, bool $includeDirs): array;
}
