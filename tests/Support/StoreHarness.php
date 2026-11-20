<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Domain\Contracts\CacheStore;

/**
 * What the contract tests need to run against a store: a fresh, empty store,
 * and a way to let time pass for it.
 *
 * Each adapter brings a harness (an array for the in-memory store, a real Redis
 * for the Redis adapter), and every contract test runs on all of them.
 */
interface StoreHarness
{
    /**
     * A store with nothing in it.
     */
    public function store(): CacheStore;

    /**
     * Let time pass, so entries with a time to live can expire.
     */
    public function advance(int $seconds): void;
}
