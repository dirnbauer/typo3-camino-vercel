<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Webconsulting\Typo3VercelSolrDemo\Content\SolrSearchContent;

final class SolrSearchContentTest extends TestCase
{
    private string|false $originalStartupTimeout;

    protected function setUp(): void
    {
        $this->originalStartupTimeout = getenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT');
    }

    protected function tearDown(): void
    {
        if ($this->originalStartupTimeout === false) {
            putenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT');
            return;
        }

        putenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT=' . $this->originalStartupTimeout);
    }

    #[DataProvider('startupTimeoutProvider')]
    public function testInternalStartupTimeoutIsBounded(?string $configured, float $expected): void
    {
        if ($configured === null) {
            putenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT');
        } else {
            putenv('TYPO3_SOLR_DEMO_STARTUP_TIMEOUT=' . $configured);
        }

        $method = new ReflectionMethod(SolrSearchContent::class, 'internalStartupTimeout');

        self::assertSame($expected, $method->invoke(new SolrSearchContent()));
    }

    /**
     * @return iterable<string, array{0:string|null,1:float}>
     */
    public static function startupTimeoutProvider(): iterable
    {
        yield 'default' => [null, 25.0];
        yield 'invalid uses default' => ['invalid', 25.0];
        yield 'lower bound' => ['1', 5.0];
        yield 'configured value' => ['18.5', 18.5];
        yield 'upper bound' => ['99', 30.0];
    }

    public function testSuggestUrlUsesTheLocalizedSearchPath(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/de/suche');
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $method = new ReflectionMethod(SolrSearchContent::class, 'suggestUrl');

        self::assertSame('/de/suche?type=7384', $method->invoke(new SolrSearchContent(), $request));
    }

    #[DataProvider('localizedCoreProvider')]
    public function testLocalizedPathSelectsMatchingSolrCore(string $path, string $expectedCore): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $method = new ReflectionMethod(SolrSearchContent::class, 'coreForRequest');

        self::assertSame($expectedCore, $method->invoke(new SolrSearchContent(), $request));
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function localizedCoreProvider(): iterable
    {
        yield 'English' => ['/search', 'core_en'];
        yield 'German' => ['/de/suche', 'core_de'];
        yield 'Spanish' => ['/es/buscar', 'core_es'];
        yield 'Chinese' => ['/zh/sousuo', 'core_zh'];
        yield 'Hungarian' => ['/hu/kereses', 'core_hu'];
    }

    public function testGermanDemoCatalogContainsLocalizedSearchTerm(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/de/suche');
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $method = new ReflectionMethod(SolrSearchContent::class, 'internalDemoDocuments');
        $documents = $method->invoke(new SolrSearchContent(), $request);

        self::assertSame('Camino', $documents[0]['title']);
        self::assertStringContainsString('Inhalte', $documents[0]['content']);
        self::assertSame('/de/', $documents[0]['url']);
    }

    #[DataProvider('localizedCatalogProvider')]
    public function testLocalizedSuggestionCatalogMatchesSolrSeed(string $path, string $core): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $method = new ReflectionMethod(SolrSearchContent::class, 'internalDemoDocuments');
        $suggestions = $method->invoke(new SolrSearchContent(), $request);
        $seed = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/services/solr/demo-documents/' . $core . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(array_column($seed, 'title'), array_column($suggestions, 'title'));
        self::assertSame(
            array_map(static fn(string $url): string => str_replace('__BASE_URL__', '', $url), array_column($seed, 'url')),
            array_column($suggestions, 'url'),
        );
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function localizedCatalogProvider(): iterable
    {
        yield 'English' => ['/search', 'core_en'];
        yield 'German' => ['/de/suche', 'core_de'];
        yield 'Spanish' => ['/es/buscar', 'core_es'];
        yield 'Chinese' => ['/zh/sousuo', 'core_zh'];
        yield 'Hungarian' => ['/hu/kereses', 'core_hu'];
    }

    public function testSuggestFrontendUsesExtSolrNativeAssets(): void
    {
        $root = dirname(__DIR__, 2);
        $configuration = (string)file_get_contents(
            $root . '/packages/typo3-vercel-solr-demo/ext_localconf.php',
        );
        $form = (string)file_get_contents(
            $root . '/packages/typo3-vercel-solr-demo/Resources/Private/Partials/Search/Form.html',
        );

        self::assertStringContainsString('plugin.tx_solr.suggest = 1', $configuration);
        self::assertStringContainsString('EXT:solr/Resources/Public/JavaScript/autocomplete.min.js', $form);
        self::assertStringContainsString('EXT:solr/Resources/Public/JavaScript/suggest_controller.js', $form);
        self::assertStringNotContainsString('jquery.', strtolower($configuration . $form));
        self::assertStringContainsString('data-suggest="{suggestUrl}"', $form);
        self::assertStringContainsString('data-suggest-catalog="{demoSuggestCatalog}"', $form);
        self::assertStringContainsString('{suggestInputClass}', $form);
        self::assertStringContainsString('demo_suggest_controller.js', $form);
    }

    public function testInternalDemoSuggestionsDoNotContactTheSolrService(): void
    {
        $previousServiceUrl = getenv('TYPO3_SOLR_SERVICE_URL');
        putenv('TYPO3_SOLR_SERVICE_URL=http://unreachable.invalid');

        try {
            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getQueryParams')->willReturn([
                'tx_solr' => ['queryString' => 'cam'],
            ]);

            $payload = json_decode((new SolrSearchContent())->renderSuggest(request: $request), true);

            self::assertIsArray($payload);
            self::assertSame(['Camino', 'Camino Route Comparison', 'FAQs', 'Packing List'], array_keys($payload['suggestions']));
            self::assertCount(4, $payload['documents']);
        } finally {
            $previousServiceUrl === false
                ? putenv('TYPO3_SOLR_SERVICE_URL')
                : putenv('TYPO3_SOLR_SERVICE_URL=' . $previousServiceUrl);
        }
    }
}
