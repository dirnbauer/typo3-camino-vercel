<?php

declare(strict_types=1);

namespace Webconsulting\Typo3CaminoVercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class HetznerProductionProfileTest extends TestCase
{
    private array $compose;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/compose.hetzner.yaml';
        self::assertFileExists($path);

        $compose = Yaml::parseFile($path);
        self::assertIsArray($compose);
        $this->compose = $compose;
    }

    public function testProductionStackKeepsAllStatefulServicesPrivateAndDurable(): void
    {
        $services = $this->compose['services'] ?? [];

        foreach (['app', 'scheduler', 'db', 'redis', 'solr', 'proxy'] as $service) {
            self::assertArrayHasKey($service, $services);
        }

        foreach (['db', 'redis', 'solr'] as $service) {
            self::assertArrayNotHasKey(
                'ports',
                $services[$service],
                sprintf('%s must not be exposed on the public host.', $service),
            );
        }

        self::assertContains('typo3-db:/var/lib/mysql', $services['db']['volumes']);
        self::assertContains('typo3-redis:/data', $services['redis']['volumes']);
        self::assertContains('typo3-solr:/var/solr', $services['solr']['volumes']);
        self::assertContains('typo3-fileadmin:/var/www/html/public/fileadmin', $services['app']['volumes']);
    }

    public function testApplicationUsesAlwaysOnLocalDependencies(): void
    {
        $app = $this->compose['services']['app'];
        $environment = $app['environment'];

        self::assertSame('0', $environment['TYPO3_SERVERLESS_FILESYSTEM']);
        self::assertSame('redis', $environment['TYPO3_CACHE_BACKEND']);
        self::assertSame('db', $environment['TYPO3_DB_HOST']);
        self::assertSame('redis', $environment['TYPO3_REDIS_HOST']);
        self::assertStringContainsString('solr:8983', $environment['TYPO3_SOLR_URL']);
        self::assertArrayHasKey('healthcheck', $app);
        self::assertSame('unless-stopped', $app['restart']);
    }

    public function testOnlyTlsProxyPublishesHostPorts(): void
    {
        $services = $this->compose['services'];

        foreach (['app', 'scheduler', 'db', 'redis', 'solr'] as $service) {
            self::assertArrayNotHasKey('ports', $services[$service]);
        }

        self::assertSame(
            ['${HTTP_PORT:-80}:80', '${HTTPS_PORT:-443}:443', '${HTTPS_PORT:-443}:443/udp'],
            $services['proxy']['ports'],
        );
    }
}
