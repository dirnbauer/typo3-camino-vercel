<?php

declare(strict_types=1);

namespace Webconsulting\Typo3CaminoVercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AlternativeDeploymentProfilesTest extends TestCase
{
    public function testRailwayApplicationUsesTheSharedContainerAndHealthEndpoint(): void
    {
        $profile = $this->jsonFile('railway.json');

        self::assertSame('DOCKERFILE', $profile['build']['builder']);
        self::assertSame('Dockerfile', $profile['build']['dockerfilePath']);
        self::assertSame('/api/health.php', $profile['deploy']['healthcheckPath']);
        self::assertSame('ON_FAILURE', $profile['deploy']['restartPolicyType']);
    }

    public function testRailwaySchedulerIsAnIndependentCronService(): void
    {
        $profile = $this->jsonFile('platforms/railway/cron.json');

        self::assertSame('Dockerfile.cron', $profile['build']['dockerfilePath']);
        self::assertSame('*/15 * * * *', $profile['deploy']['cronSchedule']);
        self::assertSame('NEVER', $profile['deploy']['restartPolicyType']);
    }

    public function testCoolifyProfileKeepsDatabasePrivateAndStateDurable(): void
    {
        $profile = Yaml::parseFile($this->root() . '/compose.coolify.yaml');
        self::assertIsArray($profile);

        $services = $profile['services'] ?? [];
        foreach (['app', 'scheduler', 'db'] as $service) {
            self::assertArrayHasKey($service, $services);
            self::assertArrayNotHasKey('ports', $services[$service]);
        }

        self::assertSame('mariadb:10.11', $services['db']['image']);
        self::assertContains('typo3-db:/var/lib/mysql', $services['db']['volumes']);
        self::assertContains('typo3-fileadmin:/tmp/typo3/fileadmin', $services['app']['volumes']);
        self::assertContains('typo3-fileadmin:/tmp/typo3/fileadmin', $services['scheduler']['volumes']);
        self::assertSame('file', $services['app']['environment']['TYPO3_CACHE_BACKEND']);
        self::assertSame('0', $services['app']['environment']['TYPO3_SOLR_ENABLED']);
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $relativePath): array
    {
        $json = file_get_contents($this->root() . '/' . $relativePath);
        self::assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
