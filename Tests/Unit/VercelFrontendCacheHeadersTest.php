<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Yaml\Yaml;
use Webconsulting\Typo3VercelStorage\Cache\FrontendEdgeCachePolicy;
use Webconsulting\Typo3VercelStorage\Middleware\VercelFrontendCacheHeaders;

#[CoversClass(FrontendEdgeCachePolicy::class)]
#[CoversClass(VercelFrontendCacheHeaders::class)]
final class VercelFrontendCacheHeadersTest extends TestCase
{
    private const ENV_NAMES = [
        'DATABASE_URL',
        'MYSQL_URL',
        'POSTGRES_URL',
        'TYPO3_DB_DRIVER',
        'TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE',
        'TYPO3_VERCEL_EDGE_CACHE_TTL',
        'VERCEL',
        'VERCEL_URL',
    ];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENV_NAMES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
            $this->setEnv($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $this->setEnv($name, $value === false ? null : $value);
        }
    }

    public function testOneClickSqliteDemoUsesEdgeCacheByDefault(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'));

        self::assertSame('public, max-age=0', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            's-maxage=300, stale-while-revalidate=300',
            $response->getHeaderLine('Vercel-CDN-Cache-Control'),
        );
        self::assertSame('Cookie, Authorization', $response->getHeaderLine('Vary'));
    }

    public function testTypo3SharedCacheResponseGetsTheShortVercelPolicy(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');
        $originResponse = new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'max-age=0, s-maxage=86400',
            'Pragma' => 'public',
            'Vary' => 'Accept-Encoding',
        ]);

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'), $originResponse);

        self::assertSame('public, max-age=0', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            's-maxage=300, stale-while-revalidate=300',
            $response->getHeaderLine('Vercel-CDN-Cache-Control'),
        );
        self::assertSame('Accept-Encoding, Cookie, Authorization', $response->getHeaderLine('Vary'));
    }

    public function testCaminoSiteEnablesSharedHeadersThroughThePolicyFlag(): void
    {
        $root = dirname(__DIR__, 2);
        $site = Yaml::parseFile($root . '/config/sites/camino/config.yaml');
        $setup = file_get_contents(
            $root . '/packages/typo3-vercel-storage/Configuration/Sets/VercelFrontendCache/setup.typoscript',
        );

        self::assertContains('webconsulting/typo3-vercel-frontend-cache', $site['dependencies']);
        self::assertIsString($setup);
        self::assertStringContainsString('TYPO3_VERCEL_SHARED_CACHE_HEADERS', $setup);
        self::assertStringContainsString('config.sendCacheHeadersForSharedCaches = force', $setup);
    }

    public function testDurableDatabaseKeepsEdgeCacheOptIn(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('DATABASE_URL', 'postgresql://example.invalid/typo3');

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'));

        self::assertFalse($response->hasHeader('Vercel-CDN-Cache-Control'));
    }

    public function testExplicitZeroDisablesDemoEdgeCache(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');
        $this->setEnv('TYPO3_VERCEL_EDGE_CACHE_TTL', '0');

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'));

        self::assertFalse($response->hasHeader('Vercel-CDN-Cache-Control'));
    }

    public function testExplicitCacheWorksWithDurableDatabase(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('DATABASE_URL', 'postgresql://example.invalid/typo3');
        $this->setEnv('TYPO3_VERCEL_EDGE_CACHE_TTL', '600');
        $this->setEnv('TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE', '900');

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'));

        self::assertSame(
            's-maxage=600, stale-while-revalidate=900',
            $response->getHeaderLine('Vercel-CDN-Cache-Control'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function privateResponseHeaderProvider(): iterable
    {
        yield 'no-store' => ['Cache-Control', 'no-store'];
        yield 'no-cache' => ['Cache-Control', 'public, no-cache'];
        yield 'private' => ['Cache-Control', 'private, max-age=0'];
        yield 'legacy pragma' => ['Pragma', 'no-cache'];
        yield 'vary all' => ['Vary', '*'];
    }

    #[DataProvider('privateResponseHeaderProvider')]
    public function testAutomaticCacheDoesNotOverridePrivateResponseHeaders(string $name, string $value): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');
        $originResponse = new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            $name => $value,
        ]);

        $response = $this->process(new ServerRequest('GET', 'https://example.test/'), $originResponse);

        self::assertFalse($response->hasHeader('Vercel-CDN-Cache-Control'));
        self::assertSame($value, $response->getHeaderLine($name));
    }

    public function testAuthenticatedRequestIsNeverShared(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');
        $request = new ServerRequest(
            'GET',
            'https://example.test/',
            ['Authorization' => 'Bearer test-token'],
        );

        $response = $this->process($request);

        self::assertFalse($response->hasHeader('Vercel-CDN-Cache-Control'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testCookieRequestCannotReuseTypo3SharedCacheHeaders(): void
    {
        $this->setEnv('VERCEL', '1');
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');
        $request = (new ServerRequest('GET', 'https://example.test/'))
            ->withHeader('Cookie', 'visitor=personalized')
            ->withCookieParams(['visitor' => 'personalized']);
        $originResponse = new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'max-age=0, s-maxage=86400',
            'CDN-Cache-Control' => 's-maxage=86400',
            'Vercel-CDN-Cache-Control' => 's-maxage=86400',
            'ETag' => '"origin"',
            'Expires' => 'Sat, 10 Jul 2027 00:00:00 GMT',
        ]);

        $response = $this->process($request, $originResponse);

        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertFalse($response->hasHeader('CDN-Cache-Control'));
        self::assertFalse($response->hasHeader('Vercel-CDN-Cache-Control'));
        self::assertFalse($response->hasHeader('ETag'));
        self::assertFalse($response->hasHeader('Expires'));
    }

    private function process(
        ServerRequestInterface $request,
        ?ResponseInterface $originResponse = null,
    ): ResponseInterface
    {
        $handler = new class ($originResponse ?? new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            '<html></html>',
        )) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return (new VercelFrontendCacheHeaders())->process($request, $handler);
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
