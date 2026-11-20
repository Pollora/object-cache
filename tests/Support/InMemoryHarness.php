<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;

/**
 * The in-memory store, with a clock the test controls.
 */
final class InMemoryHarness implements StoreHarness
{
    private readonly FrozenClock $clock;

    private readonly InMemoryCacheStore $store;

    public function __construct()
    {
        $this->clock = new FrozenClock;
        $this->store = new InMemoryCacheStore($this->clock);
    }

    public function store(): CacheStore
    {
        return $this->store;
    }

    public function advance(int $seconds): void
    {
        $this->clock->advance($seconds);
    }
}
