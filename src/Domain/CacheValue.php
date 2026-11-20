<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain;

/**
 * A value found in a cache store.
 *
 * WordPress lets code cache `false` and `null`, so a lookup cannot return the
 * raw value and use `false` for a miss. A store returns a `CacheValue` for a hit
 * and `null` for a miss, which keeps the two apart.
 */
final readonly class CacheValue
{
    /**
     * @param  mixed  $value  The cached value, exactly as it was stored.
     */
    public function __construct(public mixed $value) {}
}
