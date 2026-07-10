<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;
use Webconsulting\Typo3VercelBlobStorage\DirectUpload\UploadReceipt;
use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

#[CoversClass(UploadReceipt::class)]
final class UploadReceiptTest extends TestCase
{
    private mixed $originalConfiguration = null;

    protected function setUp(): void
    {
        $this->originalConfiguration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    protected function tearDown(): void
    {
        if ($this->originalConfiguration === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalConfiguration;
        }
    }

    public function testIssuesAndVerifiesReceipt(): void
    {
        $receipt = new UploadReceipt(new HashService());
        $encoded = $receipt->issue([
            'user' => 7,
            'pathname' => 'typo3/user_upload/video.mp4',
            'expires' => time() + 60,
        ]);

        $payload = $receipt->verify($encoded);

        self::assertSame(1, $payload['v']);
        self::assertSame(7, $payload['user']);
        self::assertSame('typo3/user_upload/video.mp4', $payload['pathname']);
    }

    public function testRejectsTamperedAndExpiredReceipts(): void
    {
        $receipt = new UploadReceipt(new HashService());
        $encoded = $receipt->issue(['expires' => time() + 60]);

        try {
            $receipt->verify('x' . $encoded);
            self::fail('A tampered receipt must be rejected.');
        } catch (DirectUploadException $exception) {
            self::assertSame(400, $exception->getHttpStatus());
        }

        $this->expectException(DirectUploadException::class);
        $receipt->verify($receipt->issue(['expires' => time() - 1]));
    }
}
