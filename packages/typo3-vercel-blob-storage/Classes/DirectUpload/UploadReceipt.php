<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\DirectUpload;

use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use Webconsulting\Typo3VercelBlobStorage\Exception\DirectUploadException;

final readonly class UploadReceipt
{
    private const VERSION = 1;
    private const MAX_LENGTH = 8192;

    public function __construct(private HashService $hashService) {}

    /**
     * @param array<string, int|string> $payload
     */
    public function issue(array $payload): string
    {
        $payload['v'] = self::VERSION;
        $encodedPayload = self::base64UrlEncode(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $signature = $this->hashService->hmac($encodedPayload, self::class, HashAlgo::SHA3_256);

        return $encodedPayload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $receipt): array
    {
        if ($receipt === '' || strlen($receipt) > self::MAX_LENGTH) {
            throw new DirectUploadException('The upload authorization is invalid. Select the file again.', 400);
        }

        $parts = explode('.', $receipt, 2);
        if (count($parts) !== 2
            || !$this->hashService->validateHmac($parts[0], self::class, $parts[1], HashAlgo::SHA3_256)
        ) {
            throw new DirectUploadException('The upload authorization is invalid. Select the file again.', 400);
        }

        try {
            $payload = json_decode(self::base64UrlDecode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException|\RuntimeException $exception) {
            throw new DirectUploadException('The upload authorization is invalid. Select the file again.', 400, $exception);
        }

        if (!is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || !is_int($payload['expires'] ?? null)
        ) {
            throw new DirectUploadException('The upload authorization is invalid. Select the file again.', 400);
        }
        if ($payload['expires'] < time()) {
            throw new DirectUploadException('The upload authorization expired. Select the file again.', 410);
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url value.', 1720100201);
        }
        return $decoded;
    }
}
