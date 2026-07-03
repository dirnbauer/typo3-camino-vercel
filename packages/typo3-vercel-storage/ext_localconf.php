<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['vercel_s3'] = [
    'class' => \Webconsulting\Typo3VercelStorage\Resource\Driver\S3Driver::class,
    'label' => 'S3-compatible object storage',
    'flexFormDS' => 'FILE:EXT:typo3_vercel_storage/Configuration/Resource/Driver/S3DriverFlexForm.xml',
];
