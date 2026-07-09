<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelBlobStorage\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

final class VercelBlobClient
{
    private const API_VERSION = '12';

    private Client $httpClient;

    public function __construct(
        private readonly string $storeId,
        private readonly string $access,
        private readonly string $token,
        private readonly string $apiUrl = 'https://vercel.com/api/blob',
        private readonly int $retries = 3,
        private readonly int $timeout = 30,
    ) {
        $this->httpClient = new Client([
            'http_errors' => true,
            'timeout' => $this->timeout,
        ]);
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    public function access(): string
    {
        return $this->access;
    }

    public function publicUrl(string $pathname): string
    {
        return sprintf(
            'https://%s.%s.blob.vercel-storage.com/%s',
            $this->storeId,
            $this->access,
            $this->encodePathname($pathname)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function head(string $urlOrPathname): ?array
    {
        try {
            return $this->apiRequest('GET', '/', [
                'query' => ['url' => $urlOrPathname],
            ]);
        } catch (ClientException $exception) {
            if ($this->isNotFound($exception->getResponse())) {
                return null;
            }
            throw $exception;
        }
    }

    /**
     * @return array<int, array{pathname: string, size: int, uploadedAt: string, url?: string, etag?: string}>
     */
    public function listPathnames(string $prefix, ?int $limit = null): array
    {
        $cursor = null;
        $items = [];

        do {
            $pageLimit = $limit === null ? 1000 : max(1, min(1000, $limit - count($items)));
            $query = [
                'limit' => (string)$pageLimit,
                'prefix' => $prefix,
            ];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $response = $this->apiRequest('GET', '/', [
                'query' => $query,
            ]);

            foreach (($response['blobs'] ?? []) as $blob) {
                if (is_array($blob) && isset($blob['pathname']) && is_string($blob['pathname'])) {
                    $items[] = [
                        'pathname' => $blob['pathname'],
                        'size' => (int)($blob['size'] ?? 0),
                        'uploadedAt' => (string)($blob['uploadedAt'] ?? ''),
                        'url' => isset($blob['url']) && is_string($blob['url']) ? $blob['url'] : null,
                        'etag' => isset($blob['etag']) && is_string($blob['etag']) ? $blob['etag'] : null,
                    ];
                    if ($limit !== null && count($items) >= $limit) {
                        break 2;
                    }
                }
            }

            $cursor = isset($response['cursor']) && is_string($response['cursor']) && ($response['hasMore'] ?? false)
                ? $response['cursor']
                : null;
        } while ($cursor !== null);

        return $items;
    }

    public function put(string $pathname, string $contents, string $contentType, ?int $cacheControlMaxAge = null): void
    {
        $this->apiRequest('PUT', '/', [
            'query' => ['pathname' => $pathname],
            'headers' => $this->writeHeaders($contentType, $cacheControlMaxAge),
            'body' => $contents,
        ]);
    }

    public function uploadFile(string $pathname, string $localFilePath, string $contentType, ?int $cacheControlMaxAge = null): void
    {
        $handle = fopen($localFilePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open local file for Vercel Blob upload: ' . $localFilePath, 1720100001);
        }

        try {
            $this->apiRequest('PUT', '/', [
                'query' => ['pathname' => $pathname],
                'headers' => $this->writeHeaders($contentType, $cacheControlMaxAge),
                'body' => $handle,
            ]);
        } finally {
            fclose($handle);
        }
    }

    public function createFolder(string $pathname): void
    {
        $folderPathname = rtrim($pathname, '/') . '/';
        $this->apiRequest('PUT', '/', [
            'query' => ['pathname' => $folderPathname],
            'headers' => [
                'x-vercel-blob-access' => $this->access,
                'x-add-random-suffix' => '0',
                'x-allow-overwrite' => '1',
            ],
        ]);
    }

    public function copy(string $sourceUrlOrPathname, string $targetPathname): void
    {
        $this->apiRequest('PUT', '/', [
            'query' => [
                'pathname' => $targetPathname,
                'fromUrl' => $sourceUrlOrPathname,
            ],
            'headers' => [
                'x-vercel-blob-access' => $this->access,
                'x-add-random-suffix' => '0',
                'x-allow-overwrite' => '1',
            ],
        ]);
    }

    /**
     * @param array<int, string> $urlOrPathnames
     */
    public function delete(array $urlOrPathnames): void
    {
        if ($urlOrPathnames === []) {
            return;
        }

        $this->apiRequest('POST', '/delete', [
            'expectJson' => false,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['urls' => array_values($urlOrPathnames)], JSON_THROW_ON_ERROR),
        ]);
    }

    public function downloadToFile(string $pathname, string $targetPath): ResponseInterface
    {
        return $this->requestWithRetries('GET', $this->publicUrl($pathname), [
            'headers' => $this->readHeaders(),
            'sink' => $targetPath,
        ]);
    }

    public function getContents(string $pathname): string
    {
        $response = $this->requestWithRetries('GET', $this->publicUrl($pathname), [
            'headers' => $this->readHeaders(),
        ]);
        return (string)$response->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    private function apiRequest(string $method, string $path, array $options = []): array
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($path, '/');
        $expectJson = $options['expectJson'] ?? true;
        unset($options['expectJson']);

        $headers = array_merge(
            [
                'x-api-blob-request-id' => $this->storeId . ':' . time() . ':' . bin2hex(random_bytes(8)),
                'x-vercel-blob-store-id' => $this->storeId,
                'x-api-version' => self::API_VERSION,
                'authorization' => 'Bearer ' . $this->token,
            ],
            $options['headers'] ?? [],
        );
        unset($options['headers']);

        $response = $this->requestWithRetries($method, $url, $options + ['headers' => $headers]);
        $body = (string)$response->getBody();
        if (!$expectJson && trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            if (!$expectJson) {
                return [];
            }
            throw new \RuntimeException('Vercel Blob API returned a non-JSON response.', 1720100002);
        }
        return $decoded;
    }

    private function requestWithRetries(string $method, string $url, array $options): ResponseInterface
    {
        $attempt = 0;

        do {
            try {
                $requestOptions = $options;
                $requestOptions['headers']['x-api-blob-request-attempt'] = (string)$attempt;
                if (isset($requestOptions['body']) && is_resource($requestOptions['body'])) {
                    rewind($requestOptions['body']);
                }
                return $this->httpClient->request($method, $url, $requestOptions);
            } catch (ServerException $exception) {
                $attempt++;
                if ($attempt > $this->retries) {
                    throw $exception;
                }
                usleep(150000 * $attempt);
            } catch (ClientException $exception) {
                throw $exception;
            } catch (GuzzleException $exception) {
                $attempt++;
                if ($attempt > $this->retries) {
                    throw new \RuntimeException('Vercel Blob request failed: ' . $exception->getMessage(), 1720100003, $exception);
                }
                usleep(150000 * $attempt);
            }
        } while (true);
    }

    /**
     * @return array<string, string>
     */
    private function writeHeaders(string $contentType, ?int $cacheControlMaxAge): array
    {
        $headers = [
            'x-vercel-blob-access' => $this->access,
            'x-add-random-suffix' => '0',
            'x-allow-overwrite' => '1',
            'x-content-type' => $contentType,
        ];

        if ($cacheControlMaxAge !== null) {
            $headers['x-cache-control-max-age'] = (string)$cacheControlMaxAge;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function readHeaders(): array
    {
        if ($this->access === 'public') {
            return [];
        }

        return [
            'authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function isNotFound(?ResponseInterface $response): bool
    {
        if ($response === null || $response->getStatusCode() === 404) {
            return true;
        }

        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded)
            && (($decoded['error']['code'] ?? null) === 'not_found' || ($decoded['error']['code'] ?? null) === 'store_not_found');
    }

    private function encodePathname(string $pathname): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($pathname, '/'))));
    }
}
