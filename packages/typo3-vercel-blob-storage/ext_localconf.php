<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['vercel_blob'] = [
    'class' => \Webconsulting\Typo3VercelBlobStorage\Resource\Driver\BlobDriver::class,
    'label' => 'Vercel Blob storage',
    'flexFormDS' => 'FILE:EXT:typo3_vercel_blob_storage/Configuration/Resource/Driver/BlobDriverFlexForm.xml',
];
