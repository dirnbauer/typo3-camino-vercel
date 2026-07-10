<?php

declare(strict_types=1);

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'imports' => [
        '@webconsulting/typo3-vercel-blob-storage/' => 'EXT:typo3_vercel_blob_storage/Resources/Public/JavaScript/',
    ],
];
