<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelSolrDemo\Content;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class SolrSearchContent
{
    /**
     * @param array<string, mixed> $configuration
     */
    #[AsAllowedCallable]
    public function render(mixed $content = '', array $configuration = [], ?ServerRequestInterface $request = null): string
    {
        unset($content, $configuration);

        try {
            return $this->renderSearch($request);
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'TYPO3 Vercel Solr demo renderer failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));
            return $this->renderUnavailable();
        }
    }

    private function renderSearch(?ServerRequestInterface $request): string
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $query = $this->searchQuery($request);
        $result = $query === ''
            ? ['ok' => true, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0]
            : $this->querySolr($query);

        $documents = array_map(
            fn(array $document): array => $this->normalizeDocument($document),
            $result['documents'],
        );

        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:typo3_vercel_solr_demo/Resources/Private/Templates'],
            partialRootPaths: [
                'EXT:typo3_vercel_solr_demo/Resources/Private/Partials',
                'EXT:theme_camino/Resources/Private/Templates/Partials',
            ],
            request: $request,
        ));
        $view->assignMultiple([
            'available' => $result['ok'],
            'documents' => $documents,
            'formAction' => $this->formAction($request),
            'hasSearched' => $query !== '',
            'isAllResults' => $query === '*',
            'query' => $query,
            'queryTimeMs' => $result['queryTimeMs'],
            'total' => $result['total'],
        ]);

        return $view->render('Search/Results');
    }

    private function renderUnavailable(): string
    {
        return implode("\n", [
            '<div class="tx_solr container">',
            '<form action="/search" method="get" class="tx-solr-search-form">',
            '<div class="input-group">',
            '<label class="sr-only" for="tx-solr-search-field-fallback">Search the Camino guide</label>',
            '<input id="tx-solr-search-field-fallback" type="search" class="tx-solr-q form-control" name="tx_solr[q]" value="' . $this->escape($this->searchQuery()) . '" maxlength="50" autocomplete="off" />',
            '<button class="btn btn-primary tx-solr-submit" type="submit">Search</button>',
            '</div>',
            '</form>',
            $this->warmingMarkup(),
            '</div>',
        ]);
    }

    private function warmingMarkup(): string
    {
        return '<div class="alert alert-warning mt-3" role="status">Search is warming up. This search will retry automatically in a few seconds.</div>';
    }

    private function searchQuery(?ServerRequestInterface $request = null): string
    {
        $queryParameters = $request?->getQueryParams() ?? $_GET;
        $query = $queryParameters['tx_solr']['q'] ?? '';
        if (is_array($query)) {
            $query = reset($query) ?: '';
        }
        return mb_substr(trim((string)$query), 0, 50);
    }

    /**
     * @return array{ok:bool,documents:array<int,array<string,mixed>>,total:int,queryTimeMs:int}
     */
    private function querySolr(string $query): array
    {
        $coreUrl = $this->solrCoreUrl();
        if ($coreUrl === null) {
            return ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        $params = [
            'q' => $query,
            'fq' => $this->filterQuery(),
            'rows' => '10',
            'fl' => 'id,title,content,url,uid',
            'wt' => 'json',
        ];
        $url = $coreUrl . '/select?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $timeout = $this->requestTimeout();
        if ($this->usesInternalVercelSolrService()) {
            $response = $this->requestInternalServiceWithRetry($url, $timeout);
        } else {
            $response = $this->request($url, $timeout);
            if ($this->isTemporaryServiceResponse($response)) {
                sleep(1);
                $response = $this->request($url, $timeout);
            }
        }

        if ($response['status'] !== 200 || $response['body'] === '') {
            return ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        $documents = $decoded['response']['docs'] ?? [];
        if (!is_array($documents)) {
            return ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        return [
            'ok' => true,
            'documents' => array_values(array_filter($documents, 'is_array')),
            'total' => max(0, (int)($decoded['response']['numFound'] ?? count($documents))),
            'queryTimeMs' => max(0, (int)($decoded['responseHeader']['QTime'] ?? 0)),
        ];
    }

    private function requestTimeout(): float
    {
        $default = $this->usesInternalVercelSolrService() ? 4.0 : 6.0;
        $max = $this->usesInternalVercelSolrService() ? 6.0 : 10.0;
        $configured = getenv('TYPO3_SOLR_DEMO_REQUEST_TIMEOUT');
        $timeout = is_string($configured) && is_numeric($configured) ? (float)$configured : $default;
        return max(1.0, min($max, $timeout));
    }

    private function internalStartupTimeout(): float
    {
        $configured = getenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT');
        $timeout = is_string($configured) && is_numeric($configured) ? (float)$configured : 25.0;
        return max(5.0, min(30.0, $timeout));
    }

    private function filterQuery(): string
    {
        $siteHash = getenv('TYPO3_SOLR_DEMO_SITE_HASH');
        if ($siteHash === false || $siteHash === '') {
            $siteHash = $this->usesInternalVercelSolrService() ? 'vercel-demo' : '';
        }

        if ($siteHash !== '') {
            return 'siteHash:"' . addcslashes($siteHash, '"\\') . '" AND type:pages';
        }

        return 'type:pages';
    }

    private function solrCoreUrl(): ?string
    {
        $core = getenv('TYPO3_SOLR_CORE') ?: getenv('SOLR_CORE') ?: 'core_en';
        $serviceUrl = getenv('TYPO3_SOLR_SERVICE_URL')
            ?: getenv('SOLR_SERVICE_URL')
            ?: getenv('TYPO3_SOLR_INTERNAL_URL')
            ?: getenv('SOLR_INTERNAL_URL');

        if (is_string($serviceUrl) && $serviceUrl !== '') {
            return rtrim($serviceUrl, '/') . '/solr/' . rawurlencode($core);
        }

        $url = getenv('TYPO3_SOLR_URL') ?: getenv('SOLR_URL');
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        return null;
    }

    private function usesInternalVercelSolrService(): bool
    {
        foreach (['TYPO3_SOLR_SERVICE_URL', 'SOLR_SERVICE_URL', 'TYPO3_SOLR_INTERNAL_URL', 'SOLR_INTERNAL_URL'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function request(string $url, float $timeout): array
    {
        if (function_exists('curl_init')) {
            return $this->curlRequest($url, $timeout);
        }

        return $this->streamRequest($url, $timeout);
    }

    /**
     * Keep one cURL handle reusable while the private service activates.
     * The service gateway may still close or reroute individual HTTP
     * connections, so correctness comes from bounded retries and Solr's
     * readiness gate rather than connection affinity.
     *
     * @return array{status:int,body:string}
     */
    private function requestInternalServiceWithRetry(string $url, float $attemptTimeout): array
    {
        $deadline = microtime(true) + $this->internalStartupTimeout();
        if (function_exists('curl_init')) {
            return $this->curlRequestWithRetry($url, $attemptTimeout, $deadline);
        }

        $attempts = 0;
        $lastResponse = ['status' => 0, 'body' => ''];
        do {
            ++$attempts;
            $remaining = max(0.001, $deadline - microtime(true));
            $lastResponse = $this->streamRequest($url, min($attemptTimeout, $remaining));
            if (!$this->isTemporaryServiceResponse($lastResponse)) {
                break;
            }
            $this->pauseBeforeRetry($deadline, $attempts);
        } while (microtime(true) < $deadline);

        return $lastResponse;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function curlRequestWithRetry(string $url, float $attemptTimeout, float $deadline): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $startedAt = microtime(true);
        $attempts = 0;
        $connections = 0;
        $lastResponse = ['status' => 0, 'body' => ''];
        do {
            ++$attempts;
            $remainingMilliseconds = max(1, (int)(($deadline - microtime(true)) * 1000));
            $attemptMilliseconds = min((int)round($attemptTimeout * 1000), $remainingMilliseconds);
            curl_setopt_array($handle, [
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $attemptMilliseconds),
                CURLOPT_TIMEOUT_MS => $attemptMilliseconds,
            ]);

            $body = curl_exec($handle);
            $lastResponse = [
                'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => is_string($body) ? $body : '',
            ];
            $connections += (int)curl_getinfo($handle, CURLINFO_NUM_CONNECTS);

            if (!$this->isTemporaryServiceResponse($lastResponse)) {
                break;
            }
            $this->pauseBeforeRetry($deadline, $attempts);
        } while (microtime(true) < $deadline);

        curl_close($handle);

        if ($attempts > 1) {
            error_log((string)json_encode([
                'level' => $lastResponse['status'] === 200 ? 'info' : 'warning',
                'component' => 'solr-search',
                'event' => 'service-startup-retry',
                'status' => $lastResponse['status'],
                'attempts' => $attempts,
                'connections' => $connections,
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ], JSON_UNESCAPED_SLASHES));
        }

        return $lastResponse;
    }

    /**
     * @param array{status:int,body:string} $response
     */
    private function isTemporaryServiceResponse(array $response): bool
    {
        if (in_array($response['status'], [0, 502, 503, 504], true)) {
            return true;
        }

        return $response['status'] === 500
            && str_contains(strtolower($response['body']), 'starting');
    }

    private function pauseBeforeRetry(float $deadline, int $attempt): void
    {
        $remainingMicroseconds = (int)(($deadline - microtime(true)) * 1_000_000);
        if ($remainingMicroseconds <= 0) {
            return;
        }

        $backoffMicroseconds = min(1_000_000, 250_000 * $attempt);
        usleep(min($remainingMicroseconds, $backoffMicroseconds));
    }

    /**
     * @return array{status:int,body:string}
     */
    private function curlRequest(string $url, float $timeout): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT_MS => (int)min(2000, max(500, $timeout * 1000)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT_MS => (int)max(1000, $timeout * 1000),
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    /**
     * @return array{status:int,body:string}
     */
    private function streamRequest(string $url, float $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'protocol_version' => 1.1,
                'timeout' => $timeout,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int)$match[1];
                break;
            }
        }

        return ['status' => $status, 'body' => $body === false ? '' : (string)$body];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function stringField(array $document, string $field, string $default): string
    {
        $value = $document[$field] ?? $default;
        if (is_array($value)) {
            $value = reset($value) ?: $default;
        }
        return trim((string)$value);
    }

    /**
     * Keep the stock EXT:solr document fields while presenting them through a
     * small Fluid view model that cannot expose arbitrary indexed protocols.
     *
     * @param array<string, mixed> $document
     * @return array{id:string,title:string,url:string,content:string}
     */
    private function normalizeDocument(array $document): array
    {
        return [
            'id' => $this->stringField($document, 'id', ''),
            'title' => $this->stringField($document, 'title', 'Untitled'),
            'url' => $this->safeResultUrl($this->stringField($document, 'url', '#')),
            'content' => $this->truncate($this->stringField($document, 'content', ''), 320),
        ];
    }

    private function safeResultUrl(string $url): string
    {
        if ($url === '' || str_starts_with($url, '/')) {
            return $url === '' ? '#' : $url;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '#';
    }

    private function formAction(?ServerRequestInterface $request): string
    {
        $path = $request?->getUri()->getPath() ?? '/search';
        return $path !== '' && str_starts_with($path, '/') ? $path : '/search';
    }

    private function truncate(string $value, int $maxLength): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 3)) . '...';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
