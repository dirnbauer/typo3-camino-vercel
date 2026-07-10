<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\DirectUpload;

use Symfony\Component\Mime\MimeTypes;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use Webconsulting\Typo3VercelBlobStorage\Authentication\BlobCredentials;
use Webconsulting\Typo3VercelBlobStorage\Client\VercelBlobClient;
use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

final readonly class DirectUploadService
{
    public const MULTIPART_THRESHOLD = 100_000_000;
    private const DEFAULT_MAXIMUM_SIZE = 5 * 1024 * 1024 * 1024;
    private const ABSOLUTE_MAXIMUM_SIZE = 5_000_000_000_000;
    private const DEFAULT_TOKEN_TTL = 14_400;
    private const BLOCKED_EXTENSIONS = [
        'cgi', 'htm', 'html', 'js', 'mjs', 'phtml', 'shtml', 'svg', 'svgz', 'xhtml', 'xml',
    ];
    private const BLOCKED_CONTENT_TYPES = [
        'application/javascript', 'application/xhtml+xml', 'image/svg+xml', 'text/html', 'text/javascript', 'text/xml',
    ];

    public function __construct(
        private ResourceFactory $resourceFactory,
        private FileNameValidator $fileNameValidator,
        private UploadReceipt $uploadReceipt,
        private PresignedUploadFactory $presignedUploadFactory,
        private DirectUploadIndexer $directUploadIndexer,
    ) {}

    public function targetFolder(?string $combinedIdentifier): Folder
    {
        if (is_string($combinedIdentifier) && trim($combinedIdentifier) !== '') {
            try {
                $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            } catch (\Throwable $exception) {
                throw new DirectUploadException('The selected upload folder is unavailable.', 404, $exception);
            }
            if ($folder->getStorage()->getDriverType() === 'vercel_blob') {
                $this->assertTargetFolder($folder);
                return $folder;
            }
        }

        // Filelist can open this module while the bundled, read-only Camino
        // storage is selected. In that case, use the editor's durable Blob
        // storage instead of presenting a misleading configuration error.
        foreach ($this->backendUser()->getFileStorages() as $storage) {
            if ($storage->getDriverType() !== 'vercel_blob' || !$storage->isOnline() || !$storage->isWritable()) {
                continue;
            }
            try {
                $folder = $storage->getDefaultFolder();
                $this->assertTargetFolder($folder);
                return $folder;
            } catch (\Throwable) {
                continue;
            }
        }

        throw new DirectUploadException(
            'No writable Vercel Blob folder is available. Connect Blob and enable the vercel_blob FAL storage first.',
            503,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|string>
     */
    public function prepare(array $input): array
    {
        $folder = $this->targetFolder($this->stringValue($input, 'folder'));
        $storage = $folder->getStorage();
        $originalName = $this->requiredString($input, 'name', 'A file name is required.');
        $fileName = $storage->sanitizeFileName(basename(str_replace('\\', '/', $originalName)), $folder);
        if (!$this->fileNameValidator->isValid($fileName)) {
            throw new DirectUploadException('This file type is not allowed by TYPO3.', 415);
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new DirectUploadException(
                'Active web files such as HTML, JavaScript, SVG, and XML are blocked for direct public uploads.',
                415,
            );
        }
        if ($folder->hasFile($fileName) || $folder->hasFolder($fileName)) {
            throw new DirectUploadException('A file or folder with this name already exists. Rename the file first.', 409);
        }

        $size = $this->positiveInteger($input['size'] ?? null);
        if ($size < 1) {
            throw new DirectUploadException('Empty files cannot be uploaded.', 400);
        }
        $maximumSize = $this->maximumSizeInBytes();
        if ($size > $maximumSize) {
            throw new DirectUploadException('The file exceeds the configured direct-upload limit.', 413);
        }

        $contentType = $this->resolveContentType($fileName, $this->stringValue($input, 'contentType'));
        if (in_array($contentType, self::BLOCKED_CONTENT_TYPES, true)) {
            throw new DirectUploadException('This active web content type is blocked for direct uploads.', 415);
        }

        $identifier = rtrim($folder->getIdentifier(), '/') . '/' . $fileName;
        $configuration = $storage->getConfiguration();
        $prefix = trim((string)($configuration['prefix'] ?? ''), '/');
        $pathname = ($prefix === '' ? '' : $prefix . '/') . ltrim($identifier, '/');
        if (strlen($pathname) > 950 || str_contains($pathname, '//')) {
            throw new DirectUploadException('The Blob pathname is too long or invalid. Shorten the folder or file name.', 400);
        }
        $access = strtolower((string)($configuration['access'] ?? 'public')) === 'private' ? 'private' : 'public';
        $cacheControlMaxAge = max(60, min(31_536_000, (int)($configuration['cacheControlMaxAge'] ?? 3600)));
        $expires = time() + $this->tokenTtl();
        $width = min(100_000, $this->positiveInteger($input['width'] ?? null));
        $height = min(100_000, $this->positiveInteger($input['height'] ?? null));

        $receipt = $this->uploadReceipt->issue([
            'user' => $this->backendUserUid(),
            'storage' => $storage->getUid(),
            'folder' => $folder->getCombinedIdentifier(),
            'identifier' => $identifier,
            'pathname' => $pathname,
            'name' => $fileName,
            'size' => $size,
            'contentType' => $contentType,
            'access' => $access,
            'cache' => $cacheControlMaxAge,
            'width' => $width,
            'height' => $height,
            'expires' => $expires,
        ]);

        return [
            'receipt' => $receipt,
            'pathname' => $pathname,
            'fileName' => $fileName,
            'contentType' => $contentType,
            'access' => $access,
            'maximumSizeInBytes' => $maximumSize,
            'multipartThreshold' => self::MULTIPART_THRESHOLD,
            'expires' => $expires,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{type: string, presignedUrlPayload: array{delegationToken: string, signature: string, params: array<string, string>}}
     */
    public function authorize(array $event): array
    {
        if (($event['type'] ?? null) !== 'blob.generate-presigned-url' || !is_array($event['payload'] ?? null)) {
            throw new DirectUploadException('Invalid Vercel Blob authorization request.', 400);
        }
        $payload = $event['payload'];
        $pathname = $this->requiredString($payload, 'pathname', 'The Blob pathname is missing.');
        $receipt = $this->requiredString($payload, 'clientPayload', 'The upload receipt is missing.');
        $authorized = $this->authorizedReceipt($receipt);
        if ($authorized['pathname'] !== $pathname) {
            throw new DirectUploadException('The requested Blob pathname does not match the upload receipt.', 403);
        }

        $folder = $this->targetFolder($authorized['folder']);
        if ($folder->hasFile($authorized['name']) || $folder->hasFolder($authorized['name'])) {
            throw new DirectUploadException('A file or folder with this name now exists. Rename the file and retry.', 409);
        }

        $storage = $folder->getStorage();
        $configuration = $storage->getConfiguration();
        $tokenEnvName = trim((string)($configuration['tokenEnvName'] ?? 'BLOB_READ_WRITE_TOKEN')) ?: 'BLOB_READ_WRITE_TOKEN';
        $storeId = BlobCredentials::resolveStoreId($configuration['storeId'] ?? null, $tokenEnvName);
        $token = BlobCredentials::resolveToken($configuration['token'] ?? null, $tokenEnvName, true);
        if ($storeId === null || $token === null) {
            throw new DirectUploadException('Vercel Blob credentials are unavailable for this request.', 503);
        }

        $apiUrl = rtrim((string)($configuration['apiUrl'] ?? 'https://vercel.com/api/blob'), '/');
        $client = new VercelBlobClient(
            $storeId,
            $authorized['access'],
            $token,
            $apiUrl !== '' ? $apiUrl : 'https://vercel.com/api/blob',
        );
        try {
            $signedToken = $client->issueSignedUploadToken(
                $authorized['pathname'],
                $authorized['contentType'],
                $authorized['size'],
                $authorized['expires'] * 1000,
            );
        } catch (\Throwable $exception) {
            throw new DirectUploadException('Vercel Blob could not authorize this upload. Retry in a moment.', 502, $exception);
        }

        return [
            'type' => 'blob.generate-presigned-url',
            'presignedUrlPayload' => $this->presignedUploadFactory->create(
                $signedToken,
                $authorized['pathname'],
                $authorized['contentType'],
                $authorized['size'],
                $authorized['cache'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|string|null>
     */
    public function finalize(array $input): array
    {
        $authorized = $this->authorizedReceipt($this->requiredString($input, 'receipt', 'The upload receipt is missing.'));
        $folder = $this->targetFolder($authorized['folder']);
        $storage = $folder->getStorage();
        $fileInfo = $this->remoteFileInfo($storage, $authorized['identifier']);

        if ((int)($fileInfo['size'] ?? -1) !== $authorized['size']) {
            throw new DirectUploadException('The uploaded Blob size does not match the authorized file.', 409);
        }
        if (strtolower((string)($fileInfo['mimetype'] ?? '')) !== strtolower($authorized['contentType'])) {
            throw new DirectUploadException('The uploaded Blob content type does not match the authorized file.', 409);
        }

        $file = $this->directUploadIndexer->index(
            $storage,
            $folder,
            $authorized['identifier'],
            $fileInfo,
            ['width' => $authorized['width'], 'height' => $authorized['height']],
        );

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'combinedIdentifier' => $file->getCombinedIdentifier(),
            'url' => $file->getPublicUrl(),
            'size' => $authorized['size'],
        ];
    }

    public function maximumSizeInBytes(): int
    {
        return $this->boundedEnvironmentInteger(
            'TYPO3_BLOB_DIRECT_UPLOAD_MAX_BYTES',
            self::DEFAULT_MAXIMUM_SIZE,
            1_000_000,
            self::ABSOLUTE_MAXIMUM_SIZE,
        );
    }

    private function tokenTtl(): int
    {
        return $this->boundedEnvironmentInteger('TYPO3_BLOB_DIRECT_UPLOAD_TOKEN_TTL', self::DEFAULT_TOKEN_TTL, 300, 86_400);
    }

    private function assertTargetFolder(Folder $folder): void
    {
        $storage = $folder->getStorage();
        if ($storage->getDriverType() !== 'vercel_blob') {
            throw new DirectUploadException('Large direct uploads require a Vercel Blob FAL storage.', 400);
        }
        if (!$storage->isOnline()
            || !$storage->isWritable()
            || !$storage->isWithinFileMountBoundaries($folder, true)
            || !$storage->checkFolderActionPermission('write', $folder)
            || !$storage->checkUserActionPermission('add', 'File')
        ) {
            throw new DirectUploadException('You do not have permission to upload into this folder.', 403);
        }
    }

    /**
     * @return array{user: int, storage: int, folder: string, identifier: string, pathname: string, name: string, size: int, contentType: string, access: string, cache: int, width: int, height: int, expires: int}
     */
    private function authorizedReceipt(string $receipt): array
    {
        $payload = $this->uploadReceipt->verify($receipt);
        $integerKeys = ['user', 'storage', 'size', 'cache', 'width', 'height', 'expires'];
        $stringKeys = ['folder', 'identifier', 'pathname', 'name', 'contentType', 'access'];
        foreach ($integerKeys as $key) {
            if (!is_int($payload[$key] ?? null)) {
                throw new DirectUploadException('The upload receipt is incomplete.', 400);
            }
        }
        foreach ($stringKeys as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new DirectUploadException('The upload receipt is incomplete.', 400);
            }
        }
        if ($payload['user'] !== $this->backendUserUid()) {
            throw new DirectUploadException('This upload authorization belongs to another backend user.', 403);
        }

        $folder = $this->targetFolder($payload['folder']);
        $storage = $folder->getStorage();
        $identifier = rtrim($folder->getIdentifier(), '/') . '/' . $payload['name'];
        $prefix = trim((string)($storage->getConfiguration()['prefix'] ?? ''), '/');
        $pathname = ($prefix === '' ? '' : $prefix . '/') . ltrim($identifier, '/');
        if ($storage->getUid() !== $payload['storage']
            || $identifier !== $payload['identifier']
            || $pathname !== $payload['pathname']
        ) {
            throw new DirectUploadException('The upload destination changed. Select the file again.', 409);
        }

        /** @var array{user: int, storage: int, folder: string, identifier: string, pathname: string, name: string, size: int, contentType: string, access: string, cache: int, width: int, height: int, expires: int} $payload */
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteFileInfo(ResourceStorage $storage, string $identifier): array
    {
        $lastException = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                return $storage->getFileInfoByIdentifier($identifier);
            } catch (FileDoesNotExistException $exception) {
                $lastException = $exception;
                usleep(200_000 * ($attempt + 1));
            }
        }
        throw new DirectUploadException('The uploaded Blob is not visible yet. Retry finalizing the upload.', 409, $lastException);
    }

    private function resolveContentType(string $fileName, ?string $browserContentType): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $browserContentType = strtolower(trim(explode(';', $browserContentType ?? '', 2)[0]));
        $mimeTypes = MimeTypes::getDefault();
        if ($browserContentType !== ''
            && preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $browserContentType) === 1
        ) {
            $contentTypeExtensions = $mimeTypes->getExtensions($browserContentType);
            if ($extension === '' || $contentTypeExtensions === [] || in_array($extension, $contentTypeExtensions, true)) {
                return $browserContentType;
            }
        }

        $guessedContentTypes = $extension === '' ? [] : $mimeTypes->getMimeTypes($extension);
        return $guessedContentTypes[0] ?? 'application/octet-stream';
    }

    private function backendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || empty($backendUser->user['uid'])) {
            throw new DirectUploadException('A TYPO3 backend login is required.', 401);
        }
        return $backendUser;
    }

    private function backendUserUid(): int
    {
        return (int)$this->backendUser()->user['uid'];
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key, string $message): string
    {
        $value = $this->stringValue($input, $key);
        if ($value === null) {
            throw new DirectUploadException($message, 400);
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private function stringValue(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && ctype_digit($value))) {
            return 0;
        }
        return max(0, (int)$value);
    }

    private function boundedEnvironmentInteger(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = getenv($name);
        if (!is_string($value) || !ctype_digit($value)) {
            return $default;
        }
        return max($minimum, min($maximum, (int)$value));
    }
}
