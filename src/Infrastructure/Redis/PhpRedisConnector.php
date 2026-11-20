<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Redis;

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Redis;
use RedisException;

/**
 * Connects with phpredis, on a client of its own.
 *
 * The client is never shared with anything else in the application: it is
 * configured for this cache (no serializer, no prefix) and would corrupt the
 * values of anyone else using it. Timeouts default to one second, because
 * without them phpredis waits for the PHP socket timeout (60 seconds) and a
 * Redis that stops answering would hang every request.
 */
final readonly class PhpRedisConnector implements RedisConnector
{
    /**
     * @param  array{host?: string, port?: int, path?: string, username?: string|null, password?: string|null, database?: int, connect_timeout?: float, read_timeout?: float, persistent?: bool}  $config
     *                                                                                                                                                                                                     `path` is a Unix socket and replaces `host` and `port`.
     */
    public function __construct(private array $config = []) {}

    /**
     * @throws StoreUnavailableException
     */
    public function connect(): Redis
    {
        // @codeCoverageIgnoreStart
        if (! extension_loaded('redis')) {
            throw new StoreUnavailableException('The redis extension is not loaded.');
        }

        // @codeCoverageIgnoreEnd

        $connectTimeout = (float) ($this->config['connect_timeout'] ?? 1.0);
        $readTimeout = (float) ($this->config['read_timeout'] ?? 1.0);
        $socket = $this->config['path'] ?? null;
        $host = $socket ?? ($this->config['host'] ?? '127.0.0.1');
        $port = $socket === null ? ($this->config['port'] ?? 6379) : 0;
        $redis = new Redis;

        try {
            $connected = ($this->config['persistent'] ?? false)
                ? $redis->pconnect($host, $port, $connectTimeout, null, 0, $readTimeout)
                : $redis->connect($host, $port, $connectTimeout, null, 0, $readTimeout);

            // @codeCoverageIgnoreStart
            if (! $connected) {
                throw new StoreUnavailableException(sprintf('Cannot connect to Redis at %s.', $socket === null ? $host.':'.$port : $host));
            }

            // @codeCoverageIgnoreEnd

            $this->authenticate($redis);
            $redis->select((int) ($this->config['database'] ?? 0));
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            $redis->setOption(Redis::OPT_PREFIX, '');
            $redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);
            $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);

            if (defined('Redis::OPT_THROW_ON_ERROR')) {
                $redis->setOption(Redis::OPT_THROW_ON_ERROR, true);
            }
        } catch (RedisException $redisException) {
            throw new StoreUnavailableException($redisException->getMessage(), 0, $redisException);
        }

        return $redis;
    }

    /**
     * @throws RedisException
     */
    private function authenticate(Redis $redis): void
    {
        $password = $this->config['password'] ?? null;

        if ($password === null || $password === '') {
            return;
        }

        $username = $this->config['username'] ?? null;

        $redis->auth($username === null || $username === '' ? $password : [$username, $password]);
    }
}
