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
