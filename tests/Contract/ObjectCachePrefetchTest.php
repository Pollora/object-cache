<?php

declare(strict_types=1);

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Domain\GroupRegistry;
use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchPlan;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Tests\Support\Caches;
use Pollora\ObjectCache\Tests\Support\CountingStore;
use Pollora\ObjectCache\Tests\Support\FrozenClock;
use Pollora\ObjectCache\Tests\Support\InMemoryHarness;
use Pollora\ObjectCache\Tests\Support\RedisHarness;
use Pollora\ObjectCache\Tests\Support\StoreHarness;
use Pollora\ObjectCache\Tests\Support\UnavailableStore;

/*
 * The prefetch, checked with every persistent adapter behind the cache: a request
 * remembers what it read, the next page of the same kind loads it in one call.
 */
dataset('prefetch harnesses', [
    'in-memory' => fn () => new InMemoryHarness,
    'redis' => fn () => new RedisHarness,
]);

/**
 * A request: a fresh cache over the shared store, with a plan for one kind of page.
 */
function prefetchRequest(CountingStore $store, ?PrefetchPlan $plan = null, ?GroupRegistry $groups = null): ObjectCache
{
    return Caches::make($store, groups: $groups, prefetch: $plan ?? new PrefetchPlan('front:1:shop', 50, 3600));
}

/**
 * Seed the shared store, then let one request read the keys so a profile exists.
 */
function prefetchSeed(CountingStore $store): void
{
    $writer = Caches::make($store);
    $writer->set('1', 'post one', 'posts');
    $writer->set('2', 'post two', 'posts');
    $writer->set('alloptions', ['a' => 1], 'options');

    $first = prefetchRequest($store);
    $first->get('1', 'posts');
    $first->get('2', 'posts');
    $first->get('alloptions', 'options');
    $first->savePrefetch(true);
}

it('loads what the first request read with one call, and serves the next reads from memory', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);

    $second = prefetchRequest($store);
    $store->gets = 0;
    $store->getManys = 0;

    expect($second->get('1', 'posts')->value)->toBe('post one')
        ->and($second->get('2', 'posts')->value)->toBe('post two')
        ->and($second->get('alloptions', 'options')->value)->toBe(['a' => 1])
        ->and($store->getManys)->toBe(1)
        ->and($store->gets)->toBe(1);
})->with('prefetch harnesses');

it('does nothing without a plan', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $cache = Caches::make($store);
    $cache->set('1', 'v', 'posts');
    $cache->savePrefetch(true);

    $other = Caches::make($store);

    expect($other->get('1', 'posts')->value)->toBe('v')
        ->and($store->getManys)->toBe(0)
        ->and($store->gets)->toBe(1);
})->with('prefetch harnesses');

it('keeps the profiles of two kinds of page apart', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);

    $blog = prefetchRequest($store, new PrefetchPlan('front:1:blog', 50, 3600));
    $store->getManys = 0;
    $blog->get('1', 'posts');

    expect($store->getManys)->toBe(0);
})->with('prefetch harnesses');

it('never loads a group the prefetch is told to leave alone, nor records it', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $groups = new GroupRegistry;
    $groups->addNonPrefetchable('sessions');
    $writer = Caches::make($store);
    $writer->set('visitor', 'cart v1', 'sessions');
    $writer->set('1', 'post', 'posts');

    $first = prefetchRequest($store, groups: $groups);
    $first->get('visitor', 'sessions');
    $first->get('1', 'posts');
    $first->savePrefetch(true);

    $writer->set('visitor', 'cart v2', 'sessions');

    $second = prefetchRequest($store, groups: $groups);

    expect($second->get('visitor', 'sessions')->value)->toBe('cart v2');
})->with('prefetch harnesses');

it('does not serve a stale value after another process changed it between two requests', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);
    Caches::make($store)->set('1', 'post one, edited', 'posts');

    $second = prefetchRequest($store);

    expect($second->get('1', 'posts')->value)->toBe('post one, edited');
})->with('prefetch harnesses');

it('keeps a value the request wrote before its first read', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);

    $second = prefetchRequest($store);
    $second->set('1', 'written first', 'posts');

    expect($second->get('1', 'posts')->value)->toBe('written first');
})->with('prefetch harnesses');

it('does not bring back a key deleted since the profile was written', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);
    Caches::make($store)->delete('2', 'posts');

    $second = prefetchRequest($store);

    expect($second->get('2', 'posts'))->toBeNull();
})->with('prefetch harnesses');

it('does not record a forced read, a miss, nor a non-persistent group', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $groups = new GroupRegistry;
    $groups->addNonPersistent('transient');
    $writer = Caches::make($store);
    $writer->set('1', 'v', 'posts');

    $first = prefetchRequest($store, groups: $groups);
    $first->get('1', 'posts', true);
    $first->get('missing', 'posts');
    $first->set('t', 'v', 'transient');
    $first->get('t', 'transient');
    $first->savePrefetch(true);

    $store->getManys = 0;
    prefetchRequest($store, groups: $groups)->get('1', 'posts');

    expect($store->getManys)->toBe(0);
})->with('prefetch harnesses');

it('records the keys of a multiple read', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $writer = Caches::make($store);
    $writer->setMany(['1' => 'a', '2' => 'b'], 'posts');

    $first = prefetchRequest($store);
    $first->getMany(['1', '2', '3'], 'posts');
    $first->savePrefetch(true);

    $second = prefetchRequest($store);
    $store->gets = 0;
    $store->getManys = 0;
    $second->get('1', 'posts');

    expect($store->getManys)->toBe(1)
        ->and($store->gets)->toBe(1);
})->with('prefetch harnesses');

it('does not write a profile for a page that is not a 200, nor when nothing is new', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $writer = Caches::make($store);
    $writer->set('1', 'v', 'posts');

    $notFound = prefetchRequest($store);
    $notFound->get('1', 'posts');
    $before = $store->sets;
    $notFound->savePrefetch(false);

    expect($store->sets)->toBe($before);

    $ok = prefetchRequest($store);
    $ok->get('1', 'posts');
    $ok->savePrefetch(true);
    $written = $store->sets;

    $again = prefetchRequest($store);
    $again->get('1', 'posts');
    $again->savePrefetch(true);

    expect($written)->toBe($before + 1)
        ->and($store->sets)->toBe($written);
})->with('prefetch harnesses');

it('writes no profile for a request that read nothing', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $request = prefetchRequest($store);
    $request->savePrefetch(true);

    expect($store->sets)->toBe(0);
})->with('prefetch harnesses');

it('gives a profile a lifetime, the maximum TTL included', function (): void {
    $persistent = new CountingStore(new InMemoryCacheStore($clock = new FrozenClock));
    $writer = Caches::make($persistent);
    $writer->set('1', 'v', 'posts');

    $first = prefetchRequest($persistent, new PrefetchPlan('front:1:x', 10, 100));
    $first->get('1', 'posts');
    $first->savePrefetch(true);
    $clock->advance(101);

    $store = $persistent;
    $store->getManys = 0;
    prefetchRequest($persistent, new PrefetchPlan('front:1:x', 10, 100))->get('1', 'posts');

    expect($store->getManys)->toBe(0);
});

it('is flushed with the rest of the cache', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    prefetchSeed($store);
    Caches::make($store)->flush();
    Caches::make($store)->set('1', 'back', 'posts');

    $store->getManys = 0;
    prefetchRequest($store)->get('1', 'posts');

    expect($store->getManys)->toBe(0);
})->with('prefetch harnesses');

it('survives Redis being down while loading, and degrades like any read', function (): void {
    $cache = Caches::make(new UnavailableStore, prefetch: new PrefetchPlan('front:1:shop', 50, 3600));

    expect($cache->get('1', 'posts'))->toBeNull()
        ->and($cache->isDegraded())->toBeTrue();

    $cache->savePrefetch(true);
});

it('does not save a profile once Redis has failed', function (): void {
    $store = new CountingStore(new InMemoryCacheStore);
    $cache = prefetchRequest($store);
    $cache->get('1', 'posts');
    $cache->set('x', 'v', 'posts');

    $failing = Caches::make(new UnavailableStore, prefetch: new PrefetchPlan('front:1:shop', 50, 3600));
    $failing->get('1', 'posts');
    $failing->savePrefetch(true);

    expect($failing->isDegraded())->toBeTrue();
});

it('ignores a stored profile it cannot read', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $keys = new KeyBuilder('site');
    $store->set($keys->key(ObjectCache::PROFILE_GROUP, 'front:1:shop', true, 0), 'garbage', 0);

    $request = prefetchRequest($store);
    $store->getManys = 0;

    expect($request->get('1', 'posts'))->toBeNull()
        ->and($store->getManys)->toBe(0);
})->with('prefetch harnesses');

it('keeps at most the profile limit of keys', function (StoreHarness $harness): void {
    $store = new CountingStore($harness->store());
    $writer = Caches::make($store);
    $first = prefetchRequest($store, new PrefetchPlan('front:1:shop', 2, 3600));

    foreach (['1', '2', '3'] as $id) {
        $writer->set($id, 'v'.$id, 'posts');
        $first->get($id, 'posts');
    }

    $first->savePrefetch(true);

    $second = prefetchRequest($store, new PrefetchPlan('front:1:shop', 2, 3600));
    $store->getManys = 0;
    $store->gets = 0;
    $second->get('1', 'posts');
    $second->get('2', 'posts');
    $second->get('3', 'posts');

    expect($store->gets)->toBe(2);
})->with('prefetch harnesses');
