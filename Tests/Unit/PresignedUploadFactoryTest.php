<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3VercelBlobStorage\DirectUpload\PresignedUploadFactory;
use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

#[CoversClass(PresignedUploadFactory::class)]
final class PresignedUploadFactoryTest extends TestCase
{
    private const PATHNAME = 'typo3/user_upload/video.mp4';
    private const MAXIMUM_SIZE = 5_368_709_120;
    private const DELEGATION_TOKEN = 'eyJzdG9yZUlkIjoic3RvcmVfdGVzdCIsInBhdGhuYW1lIjoidHlwbzMvdXNlcl91cGxvYWQvdmlkZW8ubXA0Iiwib3BlcmF0aW9ucyI6WyJwdXQiXSwidmFsaWRVbnRpbCI6NDEwMjQ0NDgwMDAwMCwibWF4aW11bVNpemVJbkJ5dGVzIjo1MzY4NzA5MTIwLCJhbGxvd2VkQ29udGVudFR5cGVzIjpbInZpZGVvL21wNCJdfQ.server-signature';

    public function testMatchesOfficialVercelBlobSdkSignature(): void
    {
        $payload = (new PresignedUploadFactory())->create(
            [
                'delegationToken' => self::DELEGATION_TOKEN,
                'clientSigningToken' => 'client-secret',
                'validUntil' => 4_102_444_800_000,
            ],
            self::PATHNAME,
            'video/mp4',
            self::MAXIMUM_SIZE,
            3600,
        );

        self::assertSame('U8bYgYO11u9TEdHtkoAu9z0UsCxLBenEaMs9Z9PJG0Q', $payload['signature']);
        self::assertSame('false', $payload['params']['vercel-blob-allow-overwrite']);
        self::assertSame((string)self::MAXIMUM_SIZE, $payload['params']['vercel-blob-maximum-size-in-bytes']);
    }

    public function testRejectsDelegationForAnotherPath(): void
    {
        $this->expectException(DirectUploadException::class);

        (new PresignedUploadFactory())->create(
            [
                'delegationToken' => self::DELEGATION_TOKEN,
                'clientSigningToken' => 'client-secret',
                'validUntil' => 4_102_444_800_000,
            ],
            'typo3/user_upload/other.mp4',
            'video/mp4',
            self::MAXIMUM_SIZE,
            3600,
        );
    }
}
