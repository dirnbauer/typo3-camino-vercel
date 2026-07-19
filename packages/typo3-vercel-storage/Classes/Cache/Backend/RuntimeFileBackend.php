<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Cache\Backend;

use TYPO3\CMS\Core\Cache\Backend\FileBackend;

final class RuntimeFileBackend extends FileBackend
{
    // These no-op setters are load-bearing: TYPO3's DefaultConfiguration merges compression
    // options into the pages cache, and core FileBackend has no such setters — without them
    // the AbstractBackend constructor throws "Invalid cache backend option".
    protected function setCompression(bool $compression): void
    {
    }

    protected function setCompressionLevel(int $compressionLevel): void
    {
    }
}
