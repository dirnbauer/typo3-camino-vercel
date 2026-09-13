<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3VercelBlobStorage\Resource\Driver\BlobDriver;

/**
 * Two parts of the driver contract that the type checker now enforces and
 * that a caller can observe.
 */
final class BlobDriverIdentifierContractTest extends TestCase
{
    #[Test]
    public function anEmptyFolderIdentifierIsRejectedRatherThanAddressingTheBucketRoot(): void
    {
        $driver = $this->driver();
        $method = new \ReflectionMethod(BlobDriver::class, 'requireNonEmpty');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1720010020);
        $method->invoke($driver, '', 'Folder identifier');
    }

    #[Test]
    public function aPresentIdentifierPassesThroughUnchanged(): void
    {
        $driver = $this->driver();
        $method = new \ReflectionMethod(BlobDriver::class, 'requireNonEmpty');

        self::assertSame('/user_upload/', $method->invoke($driver, '/user_upload/', 'Folder identifier'));
    }

    /**
     * An identifier built from a key always starts with a slash, so it is
     * never empty — which is what lets the listing hand identifiers straight
     * to the entry factory.
     */
    #[Test]
    public function anIdentifierBuiltFromAKeyIsNeverEmpty(): void
    {
        $driver = $this->driver();
        $method = new \ReflectionMethod(BlobDriver::class, 'identifierFromKey');

        self::assertSame('/', $method->invoke($driver, '', true));
        self::assertSame('/a/b/', $method->invoke($driver, 'a/b/', true));
        self::assertSame('/a/b.txt', $method->invoke($driver, 'a/b.txt', false));
    }

    private function driver(): BlobDriver
    {
        $driver = new BlobDriver([
            'storeId' => 'store_test',
            'processingFolder' => '_processed_',
        ]);
        $driver->processConfiguration();

        return $driver;
    }
}
