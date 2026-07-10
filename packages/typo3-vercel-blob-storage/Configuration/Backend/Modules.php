<?php

declare(strict_types=1);

use Webconsulting\Typo3VercelBlobStorage\Controller\LargeUploadController;

return [
    'media_vercel_blob_large_upload' => [
        'parent' => 'media',
        'position' => ['after' => 'media_management'],
        'access' => 'user',
        'path' => '/module/file/vercel-blob-upload',
        'iconIdentifier' => 'actions-upload',
        'labels' => 'typo3_vercel_blob_storage.module',
        'routes' => [
            '_default' => [
                'methods' => ['GET'],
                'target' => LargeUploadController::class . '::handleRequest',
            ],
            'prepare' => [
                'path' => '/prepare',
                'methods' => ['POST'],
                'target' => LargeUploadController::class . '::prepareAction',
            ],
            'authorize' => [
                'path' => '/authorize',
                'methods' => ['POST'],
                'target' => LargeUploadController::class . '::authorizeAction',
            ],
            'finalize' => [
                'path' => '/finalize',
                'methods' => ['POST'],
                'target' => LargeUploadController::class . '::finalizeAction',
            ],
        ],
    ],
];
