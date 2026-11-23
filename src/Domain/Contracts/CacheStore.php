<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Contracts;

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;

/**
 * Where cached values live: Redis for the persistent cache, an array for the
 * request cache.
 *
 * Keys are final strings, already scoped by `KeyBuilder`. A store knows nothing
 * of groups, blogs or WordPress. A time to live is in seconds, and 0 means the
 * entry never expires.
 *
 * `add`, `replace` and `adjust` are primitives of the store, not compositions of
 * `get` and `set`: with Redis they map to `SET NX`, `SET XX` and one atomic
 * script, so two workers cannot race between the read and the write.
 *
 * Every method may throw `StoreUnavailableException` when the backend cannot be
 * reached; the object cache catches it and carries on with the request cache.
 */
interface CacheStore
{
    /**
     * Read one entry.
     *
     * @return CacheValue|null The value, or null when the key is absent or expired.
     *
     * @throws StoreUnavailableException
     */
    public function get(string $key): ?CacheValue;

    /**
     * Read several entries at once.
     *
     * @param  list<string>  $keys
     * @return array<string, CacheValue> Only the keys that were found.
     *
     * @throws StoreUnavailableException
     */
    public function getMany(array $keys): array;

    /**
     * Write an entry, replacing any existing one.
     *
     * @throws StoreUnavailableException
     */
    public function set(string $key, mixed $value, int $ttl): bool;

    /**
     * Write an entry only if the key is absent.
     *
     * @return bool False when the key already exists.
     *
     * @throws StoreUnavailableException
     */
    public function add(string $key, mixed $value, int $ttl): bool;

    /**
     * Write an entry only if the key already exists.
     *
     * @return bool False when the key is absent.
     *
     * @throws StoreUnavailableException
     */
    public function replace(string $key, mixed $value, int $ttl): bool;

    /**
     * Remove an entry.
     *
     * @return bool False when there was nothing to remove.
     *
     * @throws StoreUnavailableException
     */
    public function delete(string $key): bool;

    /**
     * Add a signed amount to a numeric entry, atomically.
     *
     * The result never goes below 0, and a value that is not numeric counts as 0,
     * as in `WP_Object_Cache::incr()` and `decr()`. The entry keeps its expiry.
     *
     * @return int|float|false The new value, or false when the key is absent.
     *
     * @throws StoreUnavailableException
     */
    public function adjust(string $key, int $delta): int|float|false;

    /**
     * Remove every entry whose key starts with the prefix.
     *
     * The prefix must not be empty: an empty prefix would empty the whole store,
     * and on a shared Redis that is somebody else's cache.
     *
     * @return int The number of entries removed.
     *
     * @throws \InvalidArgumentException When the prefix is empty.
     * @throws StoreUnavailableException
     */
    public function deleteByPrefix(string $prefix): int;
}
