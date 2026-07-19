<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Webconsulting\Typo3VercelStorage\Cache\FrontendEdgeCachePolicy;

final class VercelFrontendCacheHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $policy = new FrontendEdgeCachePolicy();
        $ttl = $policy->ttl();

        if ($ttl <= 0) {
            return $response;
        }

        // The conditional site set asks TYPO3 to emit shared-cache headers so
        // TYPO3 can classify cacheable pages. Remove those headers again when
        // request state makes the response unsafe to share.
        if (!$this->requestCanUseSharedCache($request)) {
            return $this->preventSharedCache($response);
        }

        if (!$this->responseCanUseSharedCache($response)) {
            return $response;
        }

        $cdnCacheControl = sprintf(
            's-maxage=%d, stale-while-revalidate=%d',
            $ttl,
            $policy->staleWhileRevalidate($ttl),
        );

        return $response
            ->withHeader('Cache-Control', 'public, max-age=0')
            ->withHeader('CDN-Cache-Control', $cdnCacheControl)
            ->withHeader('Vercel-CDN-Cache-Control', $cdnCacheControl)
            ->withHeader('Vercel-Cache-Tag', 'typo3-public')
            ->withHeader('Pragma', 'public')
            ->withHeader('Vary', $this->sharedCacheVary($response));
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

        return $request->getHeaderLine('Cookie') === ''
            && $request->getCookieParams() === []
            && $request->getHeaderLine('Authorization') === '';
    }

    private function responseCanUseSharedCache(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200 || $response->hasHeader('Set-Cookie')) {
            return false;
        }

        $cacheControl = strtolower(implode(',', $response->getHeader('Cache-Control')));
        foreach (['no-store', 'no-cache', 'private'] as $directive) {
            if (preg_match(sprintf('~(?:^|,)\s*%s(?:\s*(?:=|,|$))~', $directive), $cacheControl) === 1) {
                return false;
            }
        }

        if (str_contains(strtolower($response->getHeaderLine('Pragma')), 'no-cache')) {
            return false;
        }

        $vary = array_map('trim', explode(',', strtolower($response->getHeaderLine('Vary'))));
        if (in_array('*', $vary, true)) {
            return false;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        return str_starts_with($contentType, 'text/html') || $contentType === '';
    }

    private function preventSharedCache(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withoutHeader('CDN-Cache-Control')
            ->withoutHeader('Vercel-CDN-Cache-Control')
            ->withoutHeader('Vercel-Cache-Tag')
            ->withoutHeader('Expires')
            ->withoutHeader('ETag')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function sharedCacheVary(ResponseInterface $response): string
    {
        $vary = array_values(array_filter(array_map('trim', explode(',', $response->getHeaderLine('Vary')))));
        $normalized = array_map('strtolower', $vary);

        foreach (['Cookie', 'Authorization'] as $header) {
            if (!in_array(strtolower($header), $normalized, true)) {
                $vary[] = $header;
            }
        }

        return implode(', ', $vary);
    }
}
