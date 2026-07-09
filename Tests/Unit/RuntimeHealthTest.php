<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/runtime-health.php';

final class RuntimeHealthTest extends TestCase
{
    public function testRetriesOnlyTemporaryServiceStatuses(): void
    {
        foreach ([0, 500, 502, 503, 504] as $status) {
            self::assertTrue(\typo3_vercel_health_http_status_is_temporary($status));
        }

        foreach ([200, 301, 400, 401, 403, 404, 429] as $status) {
            self::assertFalse(\typo3_vercel_health_http_status_is_temporary($status));
        }
    }
}
