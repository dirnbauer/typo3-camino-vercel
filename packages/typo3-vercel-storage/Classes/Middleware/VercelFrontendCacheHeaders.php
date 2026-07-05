<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class VercelFrontendCacheHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $ttl = $this->positiveIntEnv('TYPO3_VERCEL_EDGE_CACHE_TTL', 0);

        if ($ttl <= 0 || !$this->requestCanUseSharedCache($request) || !$this->responseCanUseSharedCache($response)) {
            return $response;
        }

        $staleWhileRevalidate = $this->positiveIntEnv(
            'TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE',
            min($ttl * 5, 300),
        );

        $cdnCacheControl = sprintf('s-maxage=%d, stale-while-revalidate=%d', $ttl, $staleWhileRevalidate);

        return $response
            ->withHeader('Cache-Control', 'public, max-age=0')
            ->withHeader('CDN-Cache-Control', $cdnCacheControl)
            ->withHeader('Vercel-CDN-Cache-Control', $cdnCacheControl)
            ->withHeader('Pragma', 'public');
    }

    private function requestCanUseSharedCache(ServerRequestInterface $request): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $uri = $request->getUri();
        $path = $uri->getPath();
        if ($path === '' || str_starts_with($path, '/typo3') || str_starts_with($path, '/api/')) {
            return false;
        }

        if ($uri->getQuery() !== '') {
            return false;
        }

        return $request->getHeaderLine('Cookie') === '' && $request->getCookieParams() === [];
    }

    private function responseCanUseSharedCache(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200 || $response->hasHeader('Set-Cookie')) {
            return false;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        return str_starts_with($contentType, 'text/html') || $contentType === '';
    }

    private function positiveIntEnv(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return max(0, (int)$value);
    }
}
