<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use PHPUnit\Framework\SkippedWithMessageException;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Infrastructure\Redis\RedisCacheStore;
use Pollora\ObjectCache\Infrastructure\Redis\ValueCodec;
use Redis;
use RuntimeException;

/**
 * The Redis store, on a real Redis, in a database that belongs to the tests.
 *
 * It reads REDIS_HOST (127.0.0.1), REDIS_PORT (6379) and
 * OBJECT_CACHE_TEST_REDIS_DB (15). Every `store()` empties that database first,
 * so the database must be one nobody else uses: the harness refuses database 0.
 *
 * Without a Redis the tests are skipped on a developer's machine, and fail in CI
 * (the CI variable is set), where a skipped test would pass for nothing.
 *
 * Redis's clock cannot be moved, so `advance()` takes the seconds off the time to
 * live of every key instead, and removes the keys that run out.
 */
final class RedisHarness implements StoreHarness
{
    private ?Redis $redis = null;

    public function __construct(private readonly string $serializer = ValueCodec::SERIALIZER_PHP) {}

    public static function database(): int
    {
        return (int) (getenv('OBJECT_CACHE_TEST_REDIS_DB') ?: 15);
    }

    public static function connector(): PhpRedisConnector
    {
        return new PhpRedisConnector([
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => self::database(),
            'connect_timeout' => 1.0,
            'read_timeout' => 2.0,
        ]);
    }

    public function store(): CacheStore
    {
        $redis = $this->client();
        $redis->flushDB();

        return new RedisCacheStore(self::connector(), new ValueCodec($this->serializer));
    }

    public function advance(int $seconds): void
    {
        $redis = $this->client();

        foreach ($redis->keys('*') as $key) {
            $remaining = $redis->pttl($key);

            if ($remaining > 0 && $remaining - $seconds * 1000 <= 0) {
                $redis->del($key);
            } elseif ($remaining > 0) {
                $redis->pexpire($key, $remaining - $seconds * 1000);
            }
        }
    }

    private function client(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (self::database() === 0) {
            throw new RuntimeException('OBJECT_CACHE_TEST_REDIS_DB must not be 0: the tests empty their database.');
        }

        try {
            if (! extension_loaded('redis')) {
                throw new StoreUnavailableException('the redis extension is not loaded');
            }

            return $this->redis = self::connector()->connect();
        } catch (StoreUnavailableException $exception) {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                throw new RuntimeException('Redis is required in CI: '.$exception->getMessage(), 0, $exception);
            }

            throw new SkippedWithMessageException('No Redis for the tests: '.$exception->getMessage());
        }
    }
}
