<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

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
}
