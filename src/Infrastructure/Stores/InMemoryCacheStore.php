<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Stores;

use InvalidArgumentException;
use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Domain\Contracts\Clock;
use Pollora\ObjectCache\Infrastructure\Clock\SystemClock;

/**
 * A store that lives in a PHP array, for the length of a request.
 *
 * It is the request cache in front of Redis and the reference the Redis adapter
 * is held to: the same contract tests run on both. Like `WP_Object_Cache`, it
 * clones an object on the way in and on the way out, so a caller that changes
 * the object it stored or fetched does not change the cache.
 */
final class InMemoryCacheStore implements CacheStore
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private array $entries = [];

    private readonly Clock $clock;

    /**
     * @param  Clock|null  $clock  Decides when an entry has expired; the server's clock by default.
     */
    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock;
    }

    /**
     * Read one entry; an expired entry is a miss.
     */
    public function get(string $key): ?CacheValue
    {
        if (! $this->isLive($key)) {
            return null;
        }

        return new CacheValue(self::copy($this->entries[$key]['value']));
    }

    /**
     * Read several entries; only the live ones are returned.
     *
     * @param  list<string>  $keys
     * @return array<string, CacheValue>
     */
    public function getMany(array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            $value = $this->get($key);

            if ($value instanceof CacheValue) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /**
     * Write an entry, replacing any existing one.
     */
    public function set(string $key, mixed $value, int $ttl): bool
    {
        $this->entries[$key] = [
            'value' => self::copy($value),
            'expiresAt' => $ttl > 0 ? $this->clock->now() + $ttl : 0,
        ];

        return true;
    }

    /**
     * Write an entry only if no live entry has the key.
     */
    public function add(string $key, mixed $value, int $ttl): bool
    {
        return ! $this->isLive($key) && $this->set($key, $value, $ttl);
    }

    /**
     * Write an entry only if a live entry has the key.
     */
    public function replace(string $key, mixed $value, int $ttl): bool
    {
        return $this->isLive($key) && $this->set($key, $value, $ttl);
    }

    /**
     * Remove an entry; false when no live entry had the key.
     */
    public function delete(string $key): bool
    {
        $existed = $this->isLive($key);

        unset($this->entries[$key]);

        return $existed;
    }

    /**
     * Add a signed amount to a numeric entry, never going below 0.
     *
     * @return int|float|false The new value, or false when the key is absent.
     */
    public function adjust(string $key, int $delta): int|float|false
    {
        if (! $this->isLive($key)) {
            return false;
        }

        $current = $this->entries[$key]['value'];
        $next = (is_int($current) || is_float($current) || (is_string($current) && is_numeric($current)) ? $current : 0) + $delta;

        if ($next < 0) {
            $next = 0;
        }

        $this->entries[$key]['value'] = $next;

        return $next;
    }

    /**
     * Remove every entry whose key starts with the prefix.
     *
     * @throws InvalidArgumentException When the prefix is empty.
     */
    public function deleteByPrefix(string $prefix): int
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('A prefix flush needs a prefix: an empty one would empty the whole store.');
        }

        $removed = 0;

        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                $removed += $this->isLive($key) ? 1 : 0;
                unset($this->entries[$key]);
            }
        }

        return $removed;
    }

    /**
     * Whether the key holds an entry that has not expired. An expired entry is
     * dropped on the way.
     */
    private function isLive(string $key): bool
    {
        if (! isset($this->entries[$key])) {
            return false;
        }

        $expiresAt = $this->entries[$key]['expiresAt'];

        if ($expiresAt !== 0 && $expiresAt <= $this->clock->now()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    /**
     * An object is cloned so the cache and the caller never share it.
     */
    private static function copy(mixed $value): mixed
    {
        return is_object($value) ? clone $value : $value;
    }
}
