<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Cache\Backend;

use TYPO3\CMS\Core\Cache\Backend\RedisBackend;

/**
 * RedisBackend hardened for persistent connections to a managed TLS Redis.
 *
 * Core's backend supports pconnect but configures no retry, read timeout, or
 * keepalive, so an idle socket the provider closed surfaces as an uncaught
 * "read error on connection" and a hung server pins an FPM worker until
 * default_socket_timeout. With retries and a bounded read timeout, persistent
 * connections become safe and remove the per-request TCP+TLS handshake tax.
 */
final class VercelRedisBackend extends RedisBackend
{
    /**
     * Seconds a single Redis command may block before phpredis retries or
     * fails; caches must degrade fast, not wait on a wedged server.
     */
    protected float $readTimeout = 2.0;

    protected function setReadTimeout(float|int $readTimeout): void
    {
        $this->readTimeout = (float)$readTimeout;
    }

    public function initializeObject(): void
    {
        parent::initializeObject();

        if (!$this->connected) {
            return;
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);
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
}
