<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\KeyBuilder;

/**
 * The object WordPress holds in `$wp_object_cache`: the signatures and return
 * values of `WP_Object_Cache`, over `ObjectCache`.
 *
 * WordPress's cache says "not found" with `false` and an out parameter, takes an
 * empty group for `default`, and reports an invalid key through
 * `_doing_it_wrong()`; this class does the same and nothing more. The `wp_cache_*`
 * functions of the drop-in call these methods.
 */
final class WordPressObjectCache
{
    /** Cache hits of this request, as `WP_Object_Cache` counts them. */
    public int $cache_hits = 0;

    /** Cache misses of this request. */
    public int $cache_misses = 0;

    public function __construct(private readonly ObjectCache $cache) {}

    /**
     * Add a value if the key is not taken.
     */
    public function add(mixed $key, mixed $data, mixed $group = 'default', mixed $expire = 0): bool
    {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        if (! $this->valid($key, 'add')) {
            return false;
        }

        return $this->cache->add($key, $data, self::group($group), (int) $expire);
    }

    /**
     * Add several values.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, bool>
     */
    public function add_multiple(array $data, mixed $group = '', mixed $expire = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->add($key, $value, $group, $expire);
        }

        return $result;
    }

    /**
     * Replace a value that exists.
     */
    public function replace(mixed $key, mixed $data, mixed $group = 'default', mixed $expire = 0): bool
    {
        return $this->valid($key, 'replace') && $this->cache->replace($key, $data, self::group($group), (int) $expire);
    }

    /**
     * Store a value.
     */
    public function set(mixed $key, mixed $data, mixed $group = 'default', mixed $expire = 0): bool
    {
        return $this->valid($key, 'set') && $this->cache->set($key, $data, self::group($group), (int) $expire);
    }

    /**
     * Store several values.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, bool>
     */
    public function set_multiple(array $data, mixed $group = '', mixed $expire = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->set($key, $value, $group, $expire);
        }

        return $result;
    }

    /**
     * Read a value: `false` when it is not found, and `$found` says whether a
     * `false` was stored.
     *
     * @param  bool  $force  Read the persistent store even if this request already has the key.
     * @param  bool|null  $found  Set to whether the key was found.
     *
     * @param-out bool $found
     */
    public function get(mixed $key, mixed $group = 'default', mixed $force = false, mixed &$found = null): mixed
    {
        if (! $this->valid($key, 'get')) {
            $found = false;

            return false;
        }

        $value = $this->cache->get($key, self::group($group), (bool) $force);
        $found = $value instanceof CacheValue;
        $found ? $this->cache_hits++ : $this->cache_misses++;

        return $value instanceof CacheValue ? $value->value : false;
    }

    /**
     * Read several values; `false` for each one that is not found.
     *
     * @param  array<array-key, mixed>  $keys
     * @return array<int|string, mixed>
     */
    public function get_multiple(mixed $keys, mixed $group = 'default', mixed $force = false): array
    {
        $result = [];

        foreach ((array) $keys as $key) {
            $this->valid($key, 'get');
        }

        foreach ($this->cache->getMany((array) $keys, self::group($group), (bool) $force) as $key => $value) {
            $value === null ? $this->cache_misses++ : $this->cache_hits++;
            $result[$key] = $value === null ? false : $value->value;
        }

        return $result;
    }

    /**
     * Remove a value.
     */
    public function delete(mixed $key, mixed $group = 'default', mixed $deprecated = false): bool
    {
        return $this->valid($key, 'delete') && $this->cache->delete($key, self::group($group));
    }

    /**
     * Remove several values.
     *
     * @param  array<int|string, mixed>  $keys
     * @return array<int|string, bool>
     */
    public function delete_multiple(array $keys, mixed $group = ''): array
    {
        $result = [];

        foreach ($keys as $key) {
            $deleted = $this->delete($key, $group);

            // A key that cannot index the result (reported by delete()) has no entry, as in get_multiple().
            if (is_int($key) || is_string($key)) {
                $result[$key] = $deleted;
            }
        }

        return $result;
    }

    /**
     * Add to a number.
     */
    public function incr(mixed $key, mixed $offset = 1, mixed $group = 'default'): int|float|false
    {
        return $this->valid($key, 'incr') ? $this->cache->increment($key, (int) $offset, self::group($group)) : false;
    }

    /**
     * Subtract from a number, down to 0.
     */
    public function decr(mixed $key, mixed $offset = 1, mixed $group = 'default'): int|float|false
    {
        return $this->valid($key, 'decr') ? $this->cache->decrement($key, (int) $offset, self::group($group)) : false;
    }

    /**
     * Empty the cache of the site.
     */
    public function flush(): bool
    {
        return $this->cache->flush();
    }

    /**
     * Empty one group.
     */
    public function flush_group(mixed $group): bool
    {
        return $this->cache->flushGroup(self::group($group));
    }

    /**
     * Empty what this request cached, and leave Redis alone.
     */
    public function flush_runtime(): bool
    {
        return $this->cache->flushRuntime();
    }

    /**
     * Declare groups shared by every blog of a network.
     *
     * @param  string|list<string>  $groups
     */
    public function add_global_groups(mixed $groups): void
    {
        $this->cache->addGlobalGroups(self::names($groups));
    }

    /**
     * Declare groups that never reach Redis.
     *
     * @param  string|list<string>  $groups
     */
    public function add_non_persistent_groups(mixed $groups): void
    {
        $this->cache->addNonPersistentGroups(self::names($groups));
    }

    /**
     * Point blog-specific keys at another blog.
     */
    public function switch_to_blog(mixed $blog_id): void
    {
        $this->cache->switchToBlog((int) $blog_id);
    }

    /**
     * Whether the cache has a feature of the object cache API.
     */
    public function supports(mixed $feature): bool
    {
        return in_array($feature, ['add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group'], true);
    }

    /**
     * Clear what the blog switch left behind; the cache keys carry the blog, so only the request cache goes.
     *
     * @deprecated since WordPress 3.5, use switch_to_blog().
     */
    public function reset(): void
    {
        if (function_exists('_deprecated_function')) {
            _deprecated_function(__FUNCTION__, '3.5.0', 'WP_Object_Cache::switch_to_blog()');
        }

        $this->cache->flushRuntime();
    }

    /**
     * Print the hits and misses of the request.
     */
    public function stats(): void
    {
        echo '<p><strong>Cache Hits:</strong> '.$this->cache_hits.'<br /><strong>Cache Misses:</strong> '.$this->cache_misses.'</p>';
    }

    /**
     * The object cache behind this facade.
     */
    public function engine(): ObjectCache
    {
        return $this->cache;
    }

    /**
     * Whether the key is one WordPress accepts; if not, report it the way WordPress does.
     *
     * @phpstan-assert int|string $key
     */
    private function valid(mixed $key, string $method): bool
    {
        if (KeyBuilder::isValidKey($key)) {
            return true;
        }

        if (function_exists('_doing_it_wrong')) {
            _doing_it_wrong(
                sprintf('%s::%s', self::class, $method),
                is_string($key) ? 'Cache key must not be an empty string.' : sprintf('Cache key must be an integer or a non-empty string, %s given.', gettype($key)),
                '6.1.0',
            );
        }

        return false;
    }

    /**
     * The group of a call: anything that is not a name means `default`.
     */
    private static function group(mixed $group): string
    {
        return is_string($group) || is_int($group) ? (string) $group : 'default';
    }

    /**
     * @return string|list<string>
     */
    private static function names(mixed $groups): string|array
    {
        return is_string($groups) ? $groups : array_values(array_map(strval(...), array_filter((array) $groups, is_scalar(...))));
    }
}
