<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain;

/**
 * Which cache groups are global (shared by every site of a network) and which
 * never reach the persistent store.
 *
 * WordPress registers its own groups while it loads, and plugins add theirs. A
 * name may end with `*` to cover a family of groups (`wc_cache_*`), which is how
 * a package can describe groups whose full names it does not know.
 */
final class GroupRegistry
{
    /** @var array<string, null> */
    private array $global = [];

    /** @var array<string, null> */
    private array $nonPersistent = [];

    /** @var array<string, null> */
    private array $nonPrefetchable = [];

    /**
     * Mark groups as shared by every site of a network.
     *
     * @param  string|list<string>  $groups
     */
    public function addGlobal(string|array $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->global[$group] = null;
        }
    }

    /**
     * Mark groups as request-only: they are never written to the persistent store.
     *
     * @param  string|list<string>  $groups
     */
    public function addNonPersistent(string|array $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->nonPersistent[$group] = null;
        }
    }

    /**
     * Mark groups the prefetch must never record nor load, because a value read a moment
     * too early would be wrong (sessions, logins).
     *
     * @param  string|list<string>  $groups
     */
    public function addNonPrefetchable(string|array $groups): void
    {
        foreach ((array) $groups as $group) {
            $this->nonPrefetchable[$group] = null;
        }
    }

    /**
     * Whether the group is shared by every site of a network.
     */
    public function isGlobal(string $group): bool
    {
        return self::matches($this->global, $group);
    }

    /**
     * Whether the group stays out of the persistent store.
     */
    public function isNonPersistent(string $group): bool
    {
        return self::matches($this->nonPersistent, $group);
    }

    /**
     * Whether the prefetch leaves the group alone.
     */
    public function isNonPrefetchable(string $group): bool
    {
        return self::matches($this->nonPrefetchable, $group);
    }

    /**
     * @param  array<string, null>  $registered
     */
    private static function matches(array $registered, string $group): bool
    {
        if (array_key_exists($group, $registered)) {
            return true;
        }

        foreach (array_keys($registered) as $name) {
            $name = (string) $name;

            if (str_ends_with($name, '*') && str_starts_with($group, substr($name, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
