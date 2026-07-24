<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VercelCostGuardrailTest extends TestCase
{
    public function testProProfileDoesNotScheduleTheDeepWarmupProbe(): void
    {
        $profile = $this->proProfile();
        $crons = $profile['crons'] ?? [];

        self::assertIsArray($crons);
        self::assertNotContains(
            '/api/cron/typo3-warmup.php',
            array_column($crons, 'path'),
            'The deep warmup keeps the TYPO3 and Solr containers billable even without visitor traffic.',
        );
    }

    public function testProProfileKeepsOnlyTheBoundedSchedulerCadence(): void
    {
        $profile = $this->proProfile();

        self::assertSame(
            [
                [
                    'path' => '/api/cron/typo3-scheduler.php',
                    'schedule' => '*/15 * * * *',
                ],
            ],
            $profile['crons'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function proProfile(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/vercel.pro.json');

        self::assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
