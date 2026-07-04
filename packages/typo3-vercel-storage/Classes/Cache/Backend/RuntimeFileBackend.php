<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Cache\Backend;

use TYPO3\CMS\Core\Cache\Backend\FileBackend;

final class RuntimeFileBackend extends FileBackend
{
    protected function setCompression(bool $compression): void
    {
    }

    protected function setCompressionLevel(int $compressionLevel): void
    {
    }
}
