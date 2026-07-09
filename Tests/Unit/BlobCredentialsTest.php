<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3VercelBlobStorage\Authentication\BlobCredentials;

#[CoversClass(BlobCredentials::class)]
final class BlobCredentialsTest extends TestCase
{
    private const ENV_NAMES = [
        'BLOB_STORE_ID',
        'TYPO3_BLOB_STORE_ID',
        'BLOB_READ_WRITE_TOKEN',
        'CUSTOM_BLOB_TOKEN',
        'VERCEL_OIDC_TOKEN',
        'HTTP_X_VERCEL_OIDC_TOKEN',
        'X_VERCEL_OIDC_TOKEN',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        foreach (self::ENV_NAMES as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name]);
        }
        foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
            if (array_key_exists($name, $_SERVER)) {
                $this->originalServer[$name] = $_SERVER[$name];
            }
            unset($_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
            if (array_key_exists($name, $this->originalServer)) {
                $_SERVER[$name] = $this->originalServer[$name];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    public function testNormalizesStoreIds(): void
    {
        self::assertSame('abc123', BlobCredentials::normalizeStoreId(' store_abc123 '));
        self::assertSame('abc123', BlobCredentials::normalizeStoreId('abc123'));
        self::assertNull(BlobCredentials::normalizeStoreId(''));
        self::assertNull(BlobCredentials::normalizeStoreId(null));
    }

    public function testResolvesStoreIdFromReadWriteToken(): void
    {
        $this->setEnv('BLOB_READ_WRITE_TOKEN', 'vercel_blob_rw_store123_secret-value');

        self::assertSame('store123', BlobCredentials::resolveStoreId(null));
        self::assertSame('store123', BlobCredentials::storeIdFromReadWriteToken('vercel_blob_ro_store123_secret-value'));
    }

    public function testConfiguredStoreIdWinsOverEnvironment(): void
    {
        $this->setEnv('BLOB_STORE_ID', 'store_environment');

        self::assertSame('configured', BlobCredentials::resolveStoreId('store_configured'));
    }

    public function testRequestOidcTokenWinsOverReadWriteToken(): void
    {
        $this->setEnv('BLOB_READ_WRITE_TOKEN', 'vercel_blob_rw_store123_secret-value');
        $_SERVER['HTTP_X_VERCEL_OIDC_TOKEN'] = 'request-oidc-token';

        self::assertSame('request-oidc-token', BlobCredentials::resolveToken(null));
    }

    public function testFallsBackToEnvironmentOidcThenReadWriteToken(): void
    {
        $this->setEnv('VERCEL_OIDC_TOKEN', 'environment-oidc-token');
        $this->setEnv('BLOB_READ_WRITE_TOKEN', 'read-write-token');
        self::assertSame('environment-oidc-token', BlobCredentials::resolveToken(null));

        putenv('VERCEL_OIDC_TOKEN');
        unset($_ENV['VERCEL_OIDC_TOKEN']);
        self::assertSame('read-write-token', BlobCredentials::resolveToken(null));
    }

    public function testExplicitTokenAndCustomEnvironmentAreSupported(): void
    {
        $this->setEnv('CUSTOM_BLOB_TOKEN', 'custom-token');
        $_SERVER['HTTP_X_VERCEL_OIDC_TOKEN'] = 'request-oidc-token';

        self::assertSame('explicit-token', BlobCredentials::resolveToken('explicit-token', 'CUSTOM_BLOB_TOKEN'));
        self::assertSame('custom-token', BlobCredentials::resolveToken(null, 'CUSTOM_BLOB_TOKEN'));
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
