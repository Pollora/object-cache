<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain;

/**
 * What a time to live becomes before it reaches the persistent store.
 *
 * WordPress callers pass 0 for "no expiry" and sometimes a negative number by
 * mistake. A site can also cap every entry, so a Redis with a memory limit does
 * not fill up with entries that never leave.
 */
final readonly class TtlPolicy
{
    /**
     * @param  int  $maximum  Longest life of an entry, in seconds; 0 sets no limit.
     */
    public function __construct(public int $maximum = 0) {}

    /**
     * The time to live to store: never negative, never above the maximum.
     *
     * With a maximum, an entry that would never expire expires at the maximum.
     */
    public function normalize(int $ttl): int
    {
        $ttl = max(0, $ttl);

        if ($this->maximum <= 0) {
            return $ttl;
        }

        return $ttl === 0 ? $this->maximum : min($ttl, $this->maximum);
    }
}
