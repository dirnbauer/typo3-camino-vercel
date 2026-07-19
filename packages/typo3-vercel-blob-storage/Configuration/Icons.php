<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-typo3-vercel-blob-upload' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_vercel_blob_storage/Resources/Public/Icons/module-vercel-blob-upload.svg',
    ],
    'mimetypes-typo3-vercel-blob-storage' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:typo3_vercel_blob_storage/Resources/Public/Icons/mimetypes-vercel-blob-storage.svg',
    ],
];
