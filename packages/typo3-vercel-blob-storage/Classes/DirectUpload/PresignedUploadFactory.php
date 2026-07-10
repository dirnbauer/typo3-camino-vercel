<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\DirectUpload;

use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

final class PresignedUploadFactory
{
    private const MAX_CACHE_CONTROL_AGE = 31_536_000;

    /**
     * @param array{delegationToken: string, clientSigningToken: string, validUntil: int} $signedToken
     * @return array{delegationToken: string, signature: string, params: array<string, string>}
     */
    public function create(
        array $signedToken,
        string $pathname,
        string $contentType,
        int $maximumSizeInBytes,
        int $cacheControlMaxAge,
    ): array {
        $delegation = $this->decodeDelegation($signedToken['delegationToken']);
        $this->assertDelegation($delegation, $pathname, $contentType, $maximumSizeInBytes);

        $params = [
            'vercel-blob-allowed-content-types' => $contentType,
            'vercel-blob-maximum-size-in-bytes' => (string)$maximumSizeInBytes,
            'vercel-blob-add-random-suffix' => 'false',
            'vercel-blob-allow-overwrite' => 'false',
            'vercel-blob-cache-control-max-age' => (string)max(60, min(self::MAX_CACHE_CONTROL_AGE, $cacheControlMaxAge)),
        ];
        $lines = ['operation=put', 'pathname=' . $pathname];
        foreach ($params as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        sort($lines, SORT_STRING);

        $signature = hash_hmac('sha256', implode("\n", $lines), $signedToken['clientSigningToken'], true);

        return [
            'delegationToken' => $signedToken['delegationToken'],
            'signature' => self::base64UrlEncode($signature),
            'params' => $params,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDelegation(string $delegationToken): array
    {
        $separator = strpos($delegationToken, '.');
        if ($separator === false) {
            throw new DirectUploadException('Vercel Blob returned an invalid upload delegation.', 502);
        }

        $encodedPayload = substr($delegationToken, 0, $separator);
        $padding = strlen($encodedPayload) % 4;
        if ($padding !== 0) {
            $encodedPayload .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        if ($decoded === false) {
            throw new DirectUploadException('Vercel Blob returned an invalid upload delegation.', 502);
        }

        try {
            $payload = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DirectUploadException('Vercel Blob returned an invalid upload delegation.', 502, $exception);
        }
        if (!is_array($payload)) {
            throw new DirectUploadException('Vercel Blob returned an invalid upload delegation.', 502);
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $delegation
     */
    private function assertDelegation(
        array $delegation,
        string $pathname,
        string $contentType,
        int $maximumSizeInBytes,
    ): void {
        if (($delegation['pathname'] ?? null) !== $pathname
            || !is_array($delegation['operations'] ?? null)
            || !in_array('put', $delegation['operations'], true)
            || !is_numeric($delegation['validUntil'] ?? null)
            || (int)$delegation['validUntil'] <= (int)(microtime(true) * 1000)
            || !is_numeric($delegation['maximumSizeInBytes'] ?? null)
            || (int)$delegation['maximumSizeInBytes'] < $maximumSizeInBytes
            || !is_array($delegation['allowedContentTypes'] ?? null)
            || !$this->contentTypeIsAllowed($contentType, $delegation['allowedContentTypes'])
        ) {
            throw new DirectUploadException('Vercel Blob returned an upload delegation with the wrong scope.', 502);
        }
    }

    /**
     * @param array<int, mixed> $allowedContentTypes
     */
    private function contentTypeIsAllowed(string $contentType, array $allowedContentTypes): bool
    {
        $type = explode('/', $contentType, 2)[0] ?? '';
        return in_array($contentType, $allowedContentTypes, true)
            || ($type !== '' && in_array($type . '/*', $allowedContentTypes, true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
