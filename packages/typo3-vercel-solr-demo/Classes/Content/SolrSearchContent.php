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

    /**
     * EXT:solr's native frontend controller consumes this response shape. The
     * adapter keeps the beta Extbase suggest action out of this custom content
     * element while retaining EXT:solr's non-jQuery browser implementation.
     *
     * @param array<string, mixed> $configuration
     */
    #[AsAllowedCallable]
    public function renderSuggest(
        mixed $content = '',
        array $configuration = [],
        ?ServerRequestInterface $request = null,
    ): string {
        unset($content, $configuration);

        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $query = $this->suggestQueryString($request);
        if (mb_strlen($query) < 2) {
            return $this->encodeJson([
                'suggestions' => [],
                'suggestion' => $query,
                'documents' => [],
                'didSecondSearch' => false,
            ]);
        }

        try {
            $result = $this->usesInternalVercelSolrService()
                ? $this->queryInternalDemoSuggestions($query, $request)
                : $this->querySolr(
                    $this->prefixQuery($query),
                    4,
                    [
                        'defType' => 'edismax',
                        'qf' => 'title^6 navTitle^4 content^2 keywords',
                        'pf' => 'title^10 navTitle^6',
                        'fl' => 'id,title,content,url,uid,type',
                    ],
                    $request,
                );
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'TYPO3 Vercel Solr suggest adapter failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));
            $result = ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        if (!$result['ok']) {
            return $this->encodeJson(['status' => false]);
        }

        $documents = [];
        $suggestions = [];
        $seenTitles = [];
        foreach ($result['documents'] as $document) {
            $normalized = $this->normalizeDocument($document);
            $title = $normalized['title'];
            $titleKey = mb_strtolower($title);
            if (isset($seenTitles[$titleKey])) {
                continue;
            }
            $seenTitles[$titleKey] = true;
            $suggestions[$title] = 1;
            $documents[] = [
                'link' => $normalized['url'],
                'type' => $this->stringField($document, 'type', 'pages'),
                'title' => $title,
                'content' => $normalized['content'],
                'group' => '',
                'previewImage' => '',
            ];
        }

        return $this->encodeJson([
            'suggestions' => $suggestions,
            'suggestion' => $query,
            'documents' => $documents,
            'didSecondSearch' => false,
        ]);
    }

    private function renderSearch(?ServerRequestInterface $request): string
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $query = $this->searchQuery($request);
        $result = $query === ''
            ? ['ok' => true, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0]
            : $this->querySolr($query, request: $request);

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
            'demoSuggestCatalog' => $this->usesInternalVercelSolrService()
                ? $this->encodeJson($this->internalDemoDocuments($request))
                : '',
            'documents' => $documents,
            'formAction' => $this->formAction($request),
            'hasSearched' => $query !== '',
            'isAllResults' => $query === '*',
            'query' => $query,
            'queryTimeMs' => $result['queryTimeMs'],
            'suggestUrl' => $this->suggestUrl($request),
            'suggestInputClass' => $this->usesInternalVercelSolrService()
                ? 'tx-solr-demo-suggest'
                : 'tx-solr-suggest tx-solr-suggest-focus',
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

    private function suggestQueryString(?ServerRequestInterface $request): string
    {
        $queryParameters = $request?->getQueryParams() ?? $_GET;
        $query = $queryParameters['tx_solr']['queryString'] ?? '';
        if (is_array($query)) {
            $query = reset($query) ?: '';
        }

        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$query) ?? '';
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        return mb_substr($query, 0, 50);
    }

    private function prefixQuery(string $query): string
    {
        $tokens = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' AND ', array_map(
            static fn(string $token): string => $token . '*',
            $tokens,
        ));
    }

    /**
     * The internal Vercel Solr service contains this exact immutable seed. Do
     * not activate its JVM for every autocomplete keystroke; full searches
     * still query Solr, while external production Solr uses the live suggest
     * query above.
     *
     * @return array{ok:bool,documents:array<int,array<string,mixed>>,total:int,queryTimeMs:int}
     */
    private function queryInternalDemoSuggestions(string $query, ?ServerRequestInterface $request): array
    {
        $documents = $this->internalDemoDocuments($request);

        $tokens = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ranked = [];
        foreach ($documents as $document) {
            $score = $this->demoSuggestionScore($document, $tokens);
            if ($score > 0) {
                $document['type'] = 'pages';
                $ranked[] = ['score' => $score, 'document' => $document];
            }
        }

        usort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: strcasecmp((string)$left['document']['title'], (string)$right['document']['title']);
        });
        $matches = array_slice(array_column($ranked, 'document'), 0, 4);

        return [
            'ok' => true,
            'documents' => $matches,
            'total' => count($matches),
            'queryTimeMs' => 0,
        ];
    }

    /** @return list<array{id:string,title:string,url:string,content:string,keywords:string}> */
    private function internalDemoDocuments(?ServerRequestInterface $request = null): array
    {
        $documents = match ($this->languageCode($request)) {
            'de' => [
                ['id' => '1', 'title' => 'Camino', 'url' => '/de/', 'content' => 'Deutsche Inhalte zur Planung einer Camino-Route.', 'keywords' => 'camino inhalte route pilgerweg'],
                ['id' => '3', 'title' => 'Datenschutz', 'url' => '/de/datenschutz', 'content' => 'Informationen zu Datenschutz, DSGVO und personenbezogenen Daten.', 'keywords' => 'datenschutz dsgvo daten'],
                ['id' => '4', 'title' => 'Impressum', 'url' => '/de/impressum', 'content' => 'Impressum und rechtliche Hinweise.', 'keywords' => 'impressum recht anbieter'],
                ['id' => '5', 'title' => 'Häufige Fragen', 'url' => '/de/haeufige-fragen', 'content' => 'Fragen und Antworten zur Camino-Planung.', 'keywords' => 'fragen antworten camino'],
                ['id' => '6', 'title' => 'Packliste', 'url' => '/de/packliste', 'content' => 'Packliste für Rucksack und Reisevorbereitung.', 'keywords' => 'packliste rucksack ausrüstung camino'],
                ['id' => '7', 'title' => 'Camino-Routenvergleich', 'url' => '/de/camino-routenvergleich', 'content' => 'Camino-Routen nach Entfernung, Schwierigkeit und Etappen vergleichen.', 'keywords' => 'camino route vergleich etappen'],
            ],
            'es' => [
                ['id' => '1', 'title' => 'Camino de Santiago', 'url' => '/es/', 'content' => 'Contenido en español para planificar el Camino.', 'keywords' => 'camino contenido ruta peregrinación'],
                ['id' => '3', 'title' => 'Privacidad', 'url' => '/es/privacidad', 'content' => 'Información de privacidad y protección de datos.', 'keywords' => 'privacidad rgpd datos'],
                ['id' => '4', 'title' => 'Aviso legal', 'url' => '/es/aviso-legal', 'content' => 'Aviso legal del sitio Camino.', 'keywords' => 'aviso legal responsable'],
                ['id' => '5', 'title' => 'Preguntas frecuentes', 'url' => '/es/preguntas-frecuentes', 'content' => 'Preguntas y respuestas sobre el Camino.', 'keywords' => 'preguntas respuestas camino'],
                ['id' => '6', 'title' => 'Lista de equipaje', 'url' => '/es/lista-equipaje', 'content' => 'Lista práctica para preparar la mochila.', 'keywords' => 'equipaje mochila camino'],
                ['id' => '7', 'title' => 'Comparación de rutas', 'url' => '/es/comparacion-rutas-camino', 'content' => 'Comparación por distancia, dificultad y etapas.', 'keywords' => 'camino rutas comparación etapas'],
            ],
            'zh' => [
                ['id' => '1', 'title' => '圣地亚哥之路', 'url' => '/zh/', 'content' => '用于规划朝圣之路的中文内容。', 'keywords' => '圣地亚哥 内容 路线 朝圣'],
                ['id' => '3', 'title' => '隐私', 'url' => '/zh/yinsi', 'content' => '隐私和数据保护信息。', 'keywords' => '隐私 数据保护 权利'],
                ['id' => '4', 'title' => '法律信息', 'url' => '/zh/falv-xinxi', 'content' => '网站法律声明和运营者信息。', 'keywords' => '法律 声明 信息'],
                ['id' => '5', 'title' => '常见问题', 'url' => '/zh/changjian-wenti', 'content' => '关于路线规划的常见问题与回答。', 'keywords' => '问题 回答 路线'],
                ['id' => '6', 'title' => '行李清单', 'url' => '/zh/xingli-qingdan', 'content' => '准备背包和装备的实用清单。', 'keywords' => '行李 背包 装备'],
                ['id' => '7', 'title' => '路线比较', 'url' => '/zh/luxian-bijiao', 'content' => '按照距离、难度和阶段比较路线。', 'keywords' => '路线 比较 阶段 距离'],
            ],
            'hu' => [
                ['id' => '1', 'title' => 'Camino', 'url' => '/hu/', 'content' => 'Magyar tartalom a Camino megtervezéséhez.', 'keywords' => 'camino tartalom útvonal zarándoklat'],
                ['id' => '3', 'title' => 'Adatvédelem', 'url' => '/hu/adatvedelem', 'content' => 'Adatvédelmi és GDPR információk.', 'keywords' => 'adatvédelem gdpr adatok'],
                ['id' => '4', 'title' => 'Impresszum', 'url' => '/hu/impresszum', 'content' => 'Impresszum és jogi tájékoztató.', 'keywords' => 'impresszum jogi üzemeltető'],
                ['id' => '5', 'title' => 'Gyakori kérdések', 'url' => '/hu/gyakori-kerdesek', 'content' => 'Kérdések és válaszok a Camino tervezéséről.', 'keywords' => 'kérdések válaszok camino'],
                ['id' => '6', 'title' => 'Csomaglista', 'url' => '/hu/csomaglista', 'content' => 'Gyakorlati csomaglista hátizsákhoz és felszereléshez.', 'keywords' => 'csomaglista hátizsák felszerelés'],
                ['id' => '7', 'title' => 'Útvonal-összehasonlítás', 'url' => '/hu/utvonal-osszehasonlitas', 'content' => 'Útvonalak összehasonlítása távolság és nehézség alapján.', 'keywords' => 'camino útvonal összehasonlítás szakaszok'],
            ],
            default => [
                ['id' => '1', 'title' => 'Camino', 'url' => '/', 'content' => 'Camino demo site for planning a Camino route.', 'keywords' => 'camino demo route pilgrimage'],
                ['id' => '3', 'title' => 'Privacy', 'url' => '/privacy', 'content' => 'Privacy information including data protection and GDPR notes.', 'keywords' => 'privacy gdpr data protection'],
                ['id' => '4', 'title' => 'Imprint', 'url' => '/imprint', 'content' => 'Imprint and legal notice for the Camino demo site.', 'keywords' => 'imprint legal'],
                ['id' => '5', 'title' => 'FAQs', 'url' => '/faqs', 'content' => 'Frequently asked Camino questions and answers.', 'keywords' => 'faq questions camino'],
                ['id' => '6', 'title' => 'Packing List', 'url' => '/packing-list', 'content' => 'Practical Camino packing and backpack planning.', 'keywords' => 'packing camino backpack route'],
                ['id' => '7', 'title' => 'Camino Route Comparison', 'url' => '/camino-route-comparison', 'content' => 'Compare Camino routes, distances, difficulty, and stages.', 'keywords' => 'camino route comparison frances stages'],
            ],
        };

        return array_map(function (array $document): array {
            $document['url'] = $this->demoResultUrl($document['url']);
            return $document;
        }, $documents);
    }

    /** @param array<string, string> $document @param list<string> $tokens */
    private function demoSuggestionScore(array $document, array $tokens): int
    {
        $title = mb_strtolower($document['title']);
        $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = preg_split('/\s+/u', mb_strtolower($document['keywords']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $searchable = mb_strtolower($document['title'] . ' ' . $document['content'] . ' ' . $document['keywords']);
        $score = 0;

        foreach ($tokens as $token) {
            if (!str_contains($searchable, $token)) {
                return 0;
            }
            if (str_starts_with($title, $token)) {
                $score += 100;
            } elseif (array_any($words, static fn(string $word): bool => str_starts_with($word, $token))) {
                $score += 50;
            } elseif (array_any($keywords, static fn(string $word): bool => str_starts_with($word, $token))) {
                $score += 20;
            } else {
                $score += 5;
            }
        }

        return $score;
    }

    private function demoResultUrl(string $path): string
    {
        $base = getenv('TYPO3_SOLR_SITE_BASE');
        return is_string($base) && $base !== '' ? rtrim($base, '/') . $path : $path;
    }

    /**
     * @return array{ok:bool,documents:array<int,array<string,mixed>>,total:int,queryTimeMs:int}
     */
    private function querySolr(
        string $query,
        int $rows = 10,
        array $additionalParameters = [],
        ?ServerRequestInterface $request = null,
    ): array
    {
        $coreUrl = $this->solrCoreUrl($request);
        if ($coreUrl === null) {
            return ['ok' => false, 'documents' => [], 'total' => 0, 'queryTimeMs' => 0];
        }

        $params = array_replace([
            'q' => $query,
            'fq' => $this->filterQuery(),
            'rows' => (string)max(0, min(20, $rows)),
            'fl' => 'id,title,content,url,uid',
            'wt' => 'json',
        ], $additionalParameters);
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

    private function solrCoreUrl(?ServerRequestInterface $request = null): ?string
    {
        $serviceUrl = getenv('TYPO3_SOLR_SERVICE_URL')
            ?: getenv('SOLR_SERVICE_URL')
            ?: getenv('TYPO3_SOLR_INTERNAL_URL')
            ?: getenv('SOLR_INTERNAL_URL');

        if (is_string($serviceUrl) && $serviceUrl !== '') {
            $core = $this->coreForRequest($request);
            return rtrim($serviceUrl, '/') . '/solr/' . rawurlencode($core);
        }

        $url = getenv('TYPO3_SOLR_URL') ?: getenv('SOLR_URL');
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        return null;
    }

    private function coreForRequest(?ServerRequestInterface $request = null): string
    {
        return 'core_' . $this->languageCode($request);
    }

    private function languageCode(?ServerRequestInterface $request = null): string
    {
        $request ??= $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request?->getAttribute('language');
        if (is_object($language) && method_exists($language, 'getLanguageId')) {
            $languageId = (int)$language->getLanguageId();
            $languageCode = [0 => 'en', 1 => 'de', 2 => 'es', 3 => 'zh', 4 => 'hu'][$languageId] ?? null;
            if ($languageCode !== null) {
                return $languageCode;
            }
        }

        $path = $request?->getUri()->getPath() ?? '';
        foreach (['de', 'es', 'zh', 'hu'] as $languageCode) {
            if ($path === '/' . $languageCode || str_starts_with($path, '/' . $languageCode . '/')) {
                return $languageCode;
            }
        }

        return 'en';
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
        $headers = http_get_last_response_headers() ?? [];
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

    private function suggestUrl(?ServerRequestInterface $request): string
    {
        return $this->formAction($request) . '?type=7384';
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        return (string)json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
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
