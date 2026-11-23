<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Application;

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Domain\GroupRegistry;
use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Domain\Prefetch\AccessLog;
use Pollora\ObjectCache\Domain\Prefetch\IncompleteObjects;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchPlan;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchProfile;
use Pollora\ObjectCache\Domain\TtlPolicy;

/**
 * The object cache of a site: every operation of `WP_Object_Cache`, over a
 * persistent store and a request cache.
 *
 * A read looks in the request cache first, then in the persistent store, and
 * keeps what it found in the request cache. A write goes to both. A group
 * registered as non-persistent only ever uses the request cache.
 *
 * When the persistent store cannot be reached, the cache degrades instead of
 * failing: the failure is kept (see `failure()`), the persistent store is left
 * alone for the rest of the request, and the request cache serves on its own.
 * Nothing is thrown at WordPress.
 *
 * The methods keep the behavior of WordPress's own cache. Where WordPress
 * returns `false` for a miss, they return `null` (a stored `false` is a hit, as
 * `$found` tells in WordPress); the drop-in turns that back into WordPress's
 * signatures.
 */
final class ObjectCache
{
    /** The group that holds the prefetch profiles; never recorded, never loaded. */
    public const string PROFILE_GROUP = 'object-cache-prefetch';

    private ?StoreUnavailableException $failure = null;

    private bool $warmed = false;

    private ?AccessLog $log = null;

    /** @var list<array{0: string, 1: int|string, 2: int}> What the stored profile held when this request read it. */
    private array $storedProfile = [];

    /**
     * @param  CacheStore  $persistent  Shared by every worker and every request (Redis).
     * @param  CacheStore  $runtime  Lives for one request.
     * @param  int  $blogId  The blog the cache starts on; see `switchToBlog()`.
     * @param  PrefetchPlan|null  $prefetch  Loads, on the first read, the keys pages of this kind read before; null for none.
     */
    public function __construct(
        private readonly CacheStore $persistent,
        private readonly CacheStore $runtime,
        private readonly KeyBuilder $keys,
        private readonly GroupRegistry $groups,
        private readonly TtlPolicy $ttl = new TtlPolicy,
        private int $blogId = 1,
        private readonly ?PrefetchPlan $prefetch = null,
    ) {}

    /**
     * Read one value.
     *
     * @param  bool  $force  Read the persistent store even if the request cache has the key.
     * @return CacheValue|null The value, or null on a miss or an invalid key.
     */
    public function get(mixed $key, string $group = 'default', bool $force = false): ?CacheValue
    {
        $this->warm();
        $group = KeyBuilder::normalizeGroup($group);
        $value = $this->fetch($key, $group, $force);

        if ($value instanceof CacheValue && ! $force) {
            $this->note($key, $group);
        }

        return $value;
    }

    private function fetch(mixed $key, string $group, bool $force): ?CacheValue
    {
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return null;
        }

        if (! ($force && $this->persists($group))) {
            $hit = $this->runtime->get($storageKey);

            if ($hit instanceof CacheValue) {
                return $hit;
            }
        }

        if ($this->persists($group)) {
            try {
                $found = $this->persistent->get($storageKey);

                if ($found instanceof CacheValue) {
                    $this->runtime->set($storageKey, $found->value, 0);
                }

                return $found;
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;

                return $this->runtime->get($storageKey);
            }
        }

        return null;
    }

    /**
     * Read several values from one group.
     *
     * @param  array<array-key, mixed>  $keys
     * @param  bool  $force  Read the persistent store even if the request cache has the keys.
     * @return array<int|string, CacheValue|null> One entry per key; null on a miss or an invalid key.
     */
    public function getMany(array $keys, string $group = 'default', bool $force = false): array
    {
        $this->warm();
        $group = KeyBuilder::normalizeGroup($group);
        $result = $this->fetchMany($keys, $group, $force);

        if (! $force) {
            foreach ($result as $key => $value) {
                if ($value instanceof CacheValue) {
                    $this->note($key, $group);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return array<int|string, CacheValue|null>
     */
    private function fetchMany(array $keys, string $group, bool $force): array
    {
        $result = [];
        $pending = [];

        foreach ($keys as $key) {
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }

            $result[$key] = null;
            $storageKey = $this->storageKey($key, $group);

            if ($storageKey !== null) {
                $pending[$storageKey] = $key;
            }
        }

        if (! ($force && $this->persists($group))) {
            foreach ($this->runtime->getMany(array_keys($pending)) as $storageKey => $value) {
                $result[$pending[$storageKey]] = $value;
                unset($pending[$storageKey]);
            }
        }

        if ($pending === [] || ! $this->persists($group)) {
            return $result;
        }

        try {
            foreach ($this->persistent->getMany(array_keys($pending)) as $storageKey => $value) {
                $result[$pending[$storageKey]] = $value;
                $this->runtime->set($storageKey, $value->value, 0);
            }
        } catch (StoreUnavailableException $storeUnavailableException) {
            $this->failure = $storeUnavailableException;

            foreach ($this->runtime->getMany(array_keys($pending)) as $storageKey => $value) {
                $result[$pending[$storageKey]] = $value;
            }
        }

        return $result;
    }

    /**
     * Store a value, replacing any existing one.
     *
     * @param  int  $ttl  Seconds before the value expires; 0 for never.
     * @return bool False only for an invalid key.
     */
    public function set(mixed $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        $group = KeyBuilder::normalizeGroup($group);
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return false;
        }

        if ($this->persists($group)) {
            try {
                $this->persistent->set($storageKey, $value, $this->ttl->normalize($ttl));
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return $this->runtime->set($storageKey, $value, 0);
    }

    /**
     * Store several values in one group.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, bool>
     */
    public function setMany(array $data, string $group = 'default', int $ttl = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->set($key, $value, $group, $ttl);
        }

        return $result;
    }

    /**
     * Store a value only if the key is not taken.
     *
     * @return bool False when the key already exists, or is invalid.
     */
    public function add(mixed $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        $group = KeyBuilder::normalizeGroup($group);
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return false;
        }

        if ($this->persists($group)) {
            try {
                if (! $this->persistent->add($storageKey, $value, $this->ttl->normalize($ttl))) {
                    return false;
                }

                $this->runtime->set($storageKey, $value, 0);

                return true;
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return $this->runtime->add($storageKey, $value, 0);
    }

    /**
     * Store several values in one group, skipping the keys already taken.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, bool>
     */
    public function addMany(array $data, string $group = 'default', int $ttl = 0): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->add($key, $value, $group, $ttl);
        }

        return $result;
    }

    /**
     * Replace a value that already exists.
     *
     * @return bool False when the key does not exist, or is invalid.
     */
    public function replace(mixed $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        $group = KeyBuilder::normalizeGroup($group);
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return false;
        }

        if ($this->persists($group)) {
            try {
                if (! $this->persistent->replace($storageKey, $value, $this->ttl->normalize($ttl))) {
                    return false;
                }

                $this->runtime->set($storageKey, $value, 0);

                return true;
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return $this->runtime->replace($storageKey, $value, 0);
    }

    /**
     * Remove a value.
     *
     * @return bool False when there was nothing to remove, or the key is invalid.
     */
    public function delete(mixed $key, string $group = 'default'): bool
    {
        $group = KeyBuilder::normalizeGroup($group);
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return false;
        }

        $inRuntime = $this->runtime->delete($storageKey);

        if ($this->persists($group)) {
            try {
                return $this->persistent->delete($storageKey) || $inRuntime;
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return $inRuntime;
    }

    /**
     * Remove several values from one group.
     *
     * @param  array<array-key, mixed>  $keys
     * @return array<int|string, bool>
     */
    public function deleteMany(array $keys, string $group = 'default'): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (is_int($key) || is_string($key)) {
                $result[$key] = $this->delete($key, $group);
            }
        }

        return $result;
    }

    /**
     * Add to a numeric value. A value that is not numeric counts as 0.
     *
     * @return int|float|false The new value, or false when the key does not exist or is invalid.
     */
    public function increment(mixed $key, int $offset = 1, string $group = 'default'): int|float|false
    {
        return $this->adjust($key, $offset, $group);
    }

    /**
     * Subtract from a numeric value, down to 0 at the lowest.
     *
     * @return int|float|false The new value, or false when the key does not exist or is invalid.
     */
    public function decrement(mixed $key, int $offset = 1, string $group = 'default'): int|float|false
    {
        return $this->adjust($key, -$offset, $group);
    }

    /**
     * Empty the cache of the site: every group, in every blog.
     *
     * Only the keys of this site go; the rest of a shared Redis stays.
     */
    public function flush(): bool
    {
        $this->runtime->deleteByPrefix($this->keys->siteScope());

        if (! $this->failure instanceof StoreUnavailableException) {
            try {
                $this->persistent->deleteByPrefix($this->keys->siteScope());
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return true;
    }

    /**
     * Empty one group, in every blog.
     */
    public function flushGroup(string $group): bool
    {
        $group = KeyBuilder::normalizeGroup($group);
        $scope = $this->keys->groupScope($group);

        $this->runtime->deleteByPrefix($scope);

        if ($this->persists($group)) {
            try {
                $this->persistent->deleteByPrefix($scope);
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return true;
    }

    /**
     * Empty the request cache only, as a worker does between two jobs.
     *
     * The persistent store gets another chance too: a worker that lives for hours
     * must not stay cut off from Redis because of one failure a while ago.
     */
    public function flushRuntime(): bool
    {
        $this->runtime->deleteByPrefix($this->keys->siteScope());
        $this->failure = null;

        return true;
    }

    /**
     * Declare groups shared by every blog of a network.
     *
     * @param  string|list<string>  $groups
     */
    public function addGlobalGroups(string|array $groups): void
    {
        $this->groups->addGlobal($groups);
    }

    /**
     * Declare groups that stay out of the persistent store.
     *
     * @param  string|list<string>  $groups
     */
    public function addNonPersistentGroups(string|array $groups): void
    {
        $this->groups->addNonPersistent($groups);
    }

    /**
     * Point the keys of blog-specific groups at another blog of a network.
     */
    public function switchToBlog(int $blogId): void
    {
        $this->blogId = $blogId;
    }

    /**
     * Write what this request read, so the next page of the same kind can load it in one call.
     *
     * Called when the request ends. Nothing is written for a request that had no plan, that
     * did not end in a page (`$cacheable` false: an error, a redirect), that found Redis down,
     * or that brought nothing new.
     */
    public function savePrefetch(bool $cacheable): void
    {
        if (! $this->prefetch instanceof PrefetchPlan || ! $this->log instanceof AccessLog || ! $cacheable || $this->failure instanceof StoreUnavailableException) {
            return;
        }

        $merged = PrefetchProfile::merge($this->storedProfile, $this->log->entries(), $this->prefetch->maxKeys);

        if ($merged === null) {
            return;
        }

        try {
            $this->persistent->set($this->profileKey($this->prefetch), ['e' => $merged], $this->ttl->normalize($this->prefetch->ttl));
        } catch (StoreUnavailableException $storeUnavailableException) {
            $this->failure = $storeUnavailableException;
        }
    }

    /**
     * On the first read, load every key the profile names with one call to the persistent store.
     *
     * The values go to the request cache, where a key this request already wrote is left alone,
     * and a value holding an object whose class is not loaded yet is left out (see `IncompleteObjects`).
     * Done lazily, not when the cache is built: WordPress registers its non-persistent groups
     * after that, and the registry must be complete to know what may be loaded.
     */
    private function warm(): void
    {
        if ($this->warmed || ! $this->prefetch instanceof PrefetchPlan) {
            return;
        }

        $this->warmed = true;
        $this->log = new AccessLog($this->prefetch->maxKeys);

        if ($this->failure instanceof StoreUnavailableException) {
            return;
        }

        try {
            $profile = $this->persistent->get($this->profileKey($this->prefetch));
            $this->storedProfile = PrefetchProfile::parse($profile?->value);
            $pending = [];

            foreach ($this->storedProfile as [$group, $key, $blogId]) {
                if ($this->prefetchable($group)) {
                    $pending[] = $this->keys->key($group, $key, $this->groups->isGlobal($group), $blogId);
                }
            }

            if ($pending === []) {
                return;
            }

            foreach ($this->persistent->getMany($pending) as $storageKey => $value) {
                if (! $this->runtime->get($storageKey) instanceof CacheValue && ! IncompleteObjects::in($value->value)) {
                    $this->runtime->set($storageKey, $value->value, 0);
                }
            }
        } catch (StoreUnavailableException $storeUnavailableException) {
            $this->failure = $storeUnavailableException;
        }
    }

    /**
     * Note that a persistent group's key was read, for the profile of this kind of page.
     */
    private function note(mixed $key, string $group): void
    {
        if ($this->log instanceof AccessLog && (is_string($key) || is_int($key)) && $this->prefetchable($group)) {
            $this->log->record($group, $key, $this->blogId);
        }
    }

    /**
     * Whether a group's values may be loaded before they are asked for.
     */
    private function prefetchable(string $group): bool
    {
        return $group !== self::PROFILE_GROUP && ! $this->groups->isNonPersistent($group) && ! $this->groups->isNonPrefetchable($group);
    }

    private function profileKey(PrefetchPlan $plan): string
    {
        return $this->keys->key(self::PROFILE_GROUP, $plan->signature, true, $this->blogId);
    }

    /**
     * Whether the persistent store has failed during this request.
     */
    public function isDegraded(): bool
    {
        return $this->failure instanceof StoreUnavailableException;
    }

    /**
     * The first failure of the persistent store in this request, for the log.
     */
    public function failure(): ?StoreUnavailableException
    {
        return $this->failure;
    }

    private function adjust(mixed $key, int $delta, string $group): int|float|false
    {
        $group = KeyBuilder::normalizeGroup($group);
        $storageKey = $this->storageKey($key, $group);

        if ($storageKey === null) {
            return false;
        }

        if ($this->persists($group)) {
            try {
                $value = $this->persistent->adjust($storageKey, $delta);

                if ($value === false) {
                    $this->runtime->delete($storageKey);

                    return false;
                }

                $this->runtime->set($storageKey, $value, 0);

                return $value;
            } catch (StoreUnavailableException $exception) {
                $this->failure = $exception;
            }
        }

        return $this->runtime->adjust($storageKey, $delta);
    }

    /**
     * Whether the group goes to the persistent store, and the store still answers.
     */
    private function persists(string $group): bool
    {
        return ! $this->failure instanceof StoreUnavailableException && ! $this->groups->isNonPersistent($group);
    }

    /**
     * The key a value is stored under, or null when WordPress would refuse the key.
     */
    private function storageKey(mixed $key, string $group): ?string
    {
        if (! KeyBuilder::isValidKey($key)) {
            return null;
        }

        /** @var int|string $key */
        return $this->keys->key($group, $key, $this->groups->isGlobal($group), $this->blogId);
    }
}
