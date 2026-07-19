<?php

declare(strict_types=1);

namespace Webconsulting\Typo3VercelStorage\Cache\Backend;

use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Exception;

/**
 * RedisBackend hardened for persistent connections to a managed TLS Redis.
 *
 * Core's backend supports pconnect but configures no retry, read timeout, or
 * keepalive, so an idle socket the provider closed surfaces as an uncaught
 * "read error on connection" and a hung server pins an FPM worker until
 * default_socket_timeout. With retries and a bounded read timeout, persistent
 * connections become safe and remove the per-request TCP+TLS handshake tax.
 *
 * The hash, pages, and rootline caches also share one connection per
 * host:port:database instead of three, cutting the per-request handshake
 * from six AUTH+SELECT round trips to two. Sharing is safe because
 * keyPrefix, compression, and prefix-scoped flush() are all PHP-side state
 * of each backend instance, never connection state.
 */
final class VercelRedisBackend extends RedisBackend
{
    /** @var array<string, \Redis> */
    private static array $sharedRedis = [];

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
        $poolKey = $this->hostname . ':' . $this->port . ':' . $this->database;
        if (isset(self::$sharedRedis[$poolKey])) {
            $this->redis = self::$sharedRedis[$poolKey];
            $this->connected = true;
            return;
        }

        $this->redis = new \Redis();
        try {
            // The six-argument form bounds AUTH and SELECT on fresh sockets
            // with the read timeout instead of default_socket_timeout.
            $this->connected = $this->persistentConnection
                ? $this->redis->pconnect($this->hostname, $this->port, $this->connectionTimeout, (string)$this->database, 0, $this->readTimeout)
                : $this->redis->connect($this->hostname, $this->port, $this->connectionTimeout, null, 0, $this->readTimeout);
        } catch (\Exception $e) {
            $this->logger->alert('Could not connect to redis server.', ['exception' => $e]);
        }

        if (!$this->connected) {
            return;
        }

        $authentication = $this->getAuthentication();
        if ($authentication !== null && !$this->redis->auth($authentication)) {
            throw new Exception('Authentication to Redis failed.', 1279765134);
        }

        if ($this->database >= 0 && !$this->redis->select($this->database)) {
            throw new Exception('The given database "' . $this->database . '" could not be selected.', 1279765144);
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

        self::$sharedRedis[$poolKey] = $this->redis;
    }
}
