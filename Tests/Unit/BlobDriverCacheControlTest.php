<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3VercelBlobStorage\Resource\Driver\BlobDriver;

final class BlobDriverCacheControlTest extends TestCase
{
    /**
     * Derivatives must carry the long-lived policy in every processing
     * folder, including the cross-storage "_processed_local_" target
     * (ADR-010); ordinary files and a root-level file whose name merely
     * starts with the folder name keep the default policy.
     */
    public function testProcessedFoldersGetTheLongLivedCachePolicy(): void
    {
        $driver = new BlobDriver([
            'storeId' => 'store_test',
            'cacheControlMaxAge' => '3600',
            'processedCacheControlMaxAge' => '31536000',
            'processingFolder' => '_processed_',
        ]);
        $driver->processConfiguration();

        $method = new \ReflectionMethod(BlobDriver::class, 'cacheControlMaxAgeForIdentifier');

        self::assertSame(31536000, $method->invoke($driver, '/_processed_/a.jpg'));
        self::assertSame(31536000, $method->invoke($driver, '/_processed_local_/csm_x.jpg'));
        self::assertSame(31536000, $method->invoke($driver, '/_processed_local_/1/csm_x.jpg'));
        self::assertSame(3600, $method->invoke($driver, '/user_upload/x.jpg'));
        self::assertSame(3600, $method->invoke($driver, '/_processed_x.jpg'));
    }
}
