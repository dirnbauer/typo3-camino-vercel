<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Session\Backend;

use TYPO3\CMS\Core\Session\Backend\RedisSessionBackend;

/**
 * RedisSessionBackend hardened like the cache backend: bounded connect and
 * read timeouts, reconnect retries, and TCP keepalive, so a wedged or
 * idle-reset managed Redis cannot pin a backend request or fail the first
 * login after idling. Core's backend connects with no timeout at all and
 * writes session keys without a TTL; the expiry backstop here guarantees
 * eventual key expiry on providers running without eviction, while TYPO3's
 * probabilistic collectGarbage() remains the authoritative cleanup.
 */
final class VercelRedisSessionBackend extends RedisSessionBackend
{
    protected function initializeConnection(): void
    {
        if ($this->connected) {
            return;
        }

        try {
            $this->connected = $this->redis->pconnect(
                $this->configuration['hostname'] ?? '127.0.0.1',
                $this->configuration['port'] ?? 6379,
                2.0,
                $this->identifier
            );
        } catch (\RedisException $e) {
            $this->logger->alert('Could not connect to redis server.', ['exception' => $e]);
        }

        if (!$this->connected) {
            throw new \RuntimeException(
                'Could not connect to redis server at ' . $this->configuration['hostname'] . ':' . $this->configuration['port'],
                1482242961
            );
        }

        if ($this->getAuthentication() !== null
            && !$this->redis->auth($this->getAuthentication())
        ) {
            throw new \RuntimeException('Authentication to Redis failed.', 1481270961);
        }

        if (isset($this->configuration['database'])
            && $this->configuration['database'] >= 0
            && !$this->redis->select($this->configuration['database'])
        ) {
            throw new \RuntimeException(
                'The given database "' . $this->configuration['database'] . '" could not be selected.',
                1481270987
            );
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
        if (defined('\Redis::OPT_TCP_KEEPALIVE')) {
            $this->redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
        }
        if (defined('\Redis::OPT_MAX_RETRIES')) {
            $this->redis->setOption(\Redis::OPT_MAX_RETRIES, 2);
        }
        if (defined('\Redis::OPT_BACKOFF_ALGORITHM') && defined('\Redis::BACKOFF_ALGORITHM_CONSTANT')) {
            $this->redis->setOption(\Redis::OPT_BACKOFF_ALGORITHM, \Redis::BACKOFF_ALGORITHM_CONSTANT);
            $this->redis->setOption(\Redis::OPT_BACKOFF_BASE, 50);
        }
    }

    public function set(string $sessionId, array $sessionData): array
    {
        $sessionData = parent::set($sessionId, $sessionData);
        $this->backstopExpiry($sessionId);

        return $sessionData;
    }

    public function update(string $sessionId, array $sessionData): array
    {
        $sessionData = parent::update($sessionId, $sessionData);
        $this->backstopExpiry($sessionId);

        return $sessionData;
    }

    /**
     * Twice the session timeout: long enough that collectGarbage() (which
     * also honors permanent sessions at maximumLifetime) always acts first.
     */
    private function backstopExpiry(string $sessionId): void
    {
        $this->redis->expire(
            $this->getSessionKeyName($this->hash($sessionId)),
            2 * $this->getSessionTimeout()
        );
    }
}
