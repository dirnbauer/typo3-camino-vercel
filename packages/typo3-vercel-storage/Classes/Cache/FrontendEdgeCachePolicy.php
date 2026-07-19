<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Cache;

final class FrontendEdgeCachePolicy
{
    public function ttl(): int
    {
        return $this->positiveIntEnv('TYPO3_VERCEL_EDGE_CACHE_TTL', $this->defaultTtl());
    }

    public function staleWhileRevalidate(int $ttl): int
    {
        return $this->positiveIntEnv(
            'TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE',
            min($ttl * 5, 300),
        );
    }

    private function positiveIntEnv(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return max(0, (int)$value);
    }

    private function defaultTtl(): int
    {
        $isVercel = getenv('VERCEL') === '1' || $this->hasEnv('VERCEL_URL');
        if (!$isVercel) {
            return 0;
        }

        foreach (['DATABASE_URL', 'POSTGRES_URL', 'MYSQL_URL'] as $databaseUrl) {
            if ($this->hasEnv($databaseUrl)) {
                return 0;
            }
        }

        $driver = strtolower(trim((string)(getenv('TYPO3_DB_DRIVER') ?: 'pdo_sqlite')));
        return in_array($driver, ['sqlite', 'pdo_sqlite'], true) ? 300 : 0;
    }

    private function hasEnv(string $name): bool
    {
        $value = getenv($name);
        return is_string($value) && trim($value) !== '';
    }
}
