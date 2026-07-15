<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\DirectUpload;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Registers an already uploaded Blob without downloading it for metadata extraction.
 */
final readonly class DirectUploadIndexer
{
    public function __construct(
        private FileIndexRepository $fileIndexRepository,
        private ResourceFactory $resourceFactory,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param array<string, mixed> $fileInfo
     * @param array{width?: int, height?: int} $metadata
     */
    public function index(
        ResourceStorage $storage,
        Folder $folder,
        string $identifier,
        array $fileInfo,
        array $metadata = [],
    ): File {
        $mimeType = (string)($fileInfo['mimetype'] ?? 'application/octet-stream');
        $properties = [
            'missing' => 0,
            'type' => FileType::tryFromMimeType($mimeType)->value,
            'storage' => $storage->getUid(),
            'identifier' => $identifier,
            'identifier_hash' => $storage->hashFileIdentifier($identifier),
            'extension' => strtolower((string)PathUtility::pathinfo($identifier, PATHINFO_EXTENSION)),
            'mime_type' => $mimeType,
            'name' => PathUtility::basename($identifier),
            'sha1' => $storage->hashFileByIdentifier($identifier, 'sha1'),
            'size' => (int)($fileInfo['size'] ?? 0),
            'creation_date' => (int)($fileInfo['ctime'] ?? time()),
            'modification_date' => (int)($fileInfo['mtime'] ?? time()),
            'folder_hash' => $folder->getHashedIdentifier(),
        ];

        $existing = $this->fileIndexRepository->findOneByStorageAndIdentifier($storage, $identifier);
        $isNew = $existing === false;
        if ($isNew) {
            $record = $this->fileIndexRepository->addRaw($properties);
            $file = $this->resourceFactory->getFileObject((int)$record['uid'], $record);
        } else {
            $file = $this->resourceFactory->getFileObject((int)$existing['uid'], $existing);
            $file->updateProperties($properties);
            $this->fileIndexRepository->update($file);
        }
        $this->fileIndexRepository->updateIndexingTime($file->getUid());

        $metadata = array_filter(
            $metadata,
            static fn(int $value): bool => $value > 0,
        );
        if ($metadata !== []) {
            $file->getMetaData()->add($metadata)->save();
        }

        if ($isNew) {
            $this->eventDispatcher->dispatch(new AfterFileAddedEvent($file, $folder));
        }
        return $file;
    }
}
