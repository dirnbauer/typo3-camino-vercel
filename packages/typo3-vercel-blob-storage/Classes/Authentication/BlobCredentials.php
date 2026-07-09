<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\Authentication;

final class BlobCredentials
{
    public static function resolveStoreId(mixed $configuredStoreId, string $tokenEnvName = 'BLOB_READ_WRITE_TOKEN'): ?string
    {
        foreach ([$configuredStoreId, getenv('BLOB_STORE_ID'), getenv('TYPO3_BLOB_STORE_ID')] as $candidate) {
            $storeId = self::normalizeStoreId($candidate);
            if ($storeId !== null) {
                return $storeId;
            }
        }

        foreach (array_unique([$tokenEnvName, 'BLOB_READ_WRITE_TOKEN']) as $envName) {
            $token = getenv($envName);
            $storeId = is_string($token) ? self::storeIdFromReadWriteToken($token) : null;
            if ($storeId !== null) {
                return $storeId;
            }
        }

        return null;
    }

    public static function resolveToken(
        mixed $configuredToken,
        string $tokenEnvName = 'BLOB_READ_WRITE_TOKEN',
        bool $preferOidc = true,
    ): ?string {
        $token = self::normalizeToken($configuredToken);
        if ($token !== null) {
            return $token;
        }

        if ($tokenEnvName !== 'BLOB_READ_WRITE_TOKEN') {
            $token = self::normalizeToken(getenv($tokenEnvName));
            if ($token !== null) {
                return $token;
            }
        }

        if ($preferOidc) {
            $token = self::requestOidcToken() ?? self::normalizeToken(getenv('VERCEL_OIDC_TOKEN'));
            if ($token !== null) {
                return $token;
            }
        }

        foreach (array_unique([$tokenEnvName, 'BLOB_READ_WRITE_TOKEN']) as $envName) {
            $token = self::normalizeToken(getenv($envName));
            if ($token !== null) {
                return $token;
            }
        }

        if (!$preferOidc) {
            return self::requestOidcToken() ?? self::normalizeToken(getenv('VERCEL_OIDC_TOKEN'));
        }

        return null;
    }

    public static function requestOidcToken(): ?string
    {
        foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
            $token = self::normalizeToken($_SERVER[$name] ?? null) ?? self::normalizeToken(getenv($name));
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    public static function storeIdFromReadWriteToken(string $token): ?string
    {
        if (preg_match('/^vercel_blob_(?:rw|ro)_([^_]+)_/', trim($token), $matches) !== 1) {
            return null;
        }

        return self::normalizeStoreId($matches[1]);
    }

    public static function normalizeStoreId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, 'store_') ? substr($value, 6) : $value;
    }

    private static function normalizeToken(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
