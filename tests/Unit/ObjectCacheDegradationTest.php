<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Tests\Support\Caches;
use Pollora\ObjectCache\Tests\Support\FrozenClock;
use Pollora\ObjectCache\Tests\Support\UnavailableStore;

/*
 * When Redis is down the site keeps running on the request cache, and nothing
 * is thrown at WordPress.
 */
it('starts healthy', function (): void {
    $cache = Caches::make(new InMemoryCacheStore);

    expect($cache->isDegraded())->toBeFalse()
        ->and($cache->failure())->toBeNull();
});

it('serves every operation from the request cache when the store is down', function (): void {
    $store = new UnavailableStore;
    $cache = Caches::make($store);

    expect($cache->set('a', 1))->toBeTrue()
        ->and($cache->get('a')->value)->toBe(1)
        ->and($cache->isDegraded())->toBeTrue();

    expect($cache->add('b', 1))->toBeTrue()
        ->and($cache->add('b', 2))->toBeFalse()
        ->and($cache->replace('b', 3))->toBeTrue()
        ->and($cache->increment('b'))->toBe(4)
        ->and($cache->decrement('b', 2))->toBe(2)
        ->and($cache->getMany(['a', 'b', 'c'])['b']->value)->toBe(2)
        ->and($cache->delete('b'))->toBeTrue()
        ->and($cache->flushGroup('default'))->toBeTrue()
        ->and($cache->flush())->toBeTrue()
        ->and($cache->get('a'))->toBeNull();
});

it('reports the first failure', function (): void {
    $cache = Caches::make(new UnavailableStore);

    $cache->get('k');

    expect($cache->failure())->toBeInstanceOf(StoreUnavailableException::class)
        ->and($cache->failure()->getMessage())->toBe('Redis is down.');
});

it('gives up on the store for the rest of the request after one failure', function (): void {
    $store = new UnavailableStore;
    $cache = Caches::make($store);

    $cache->get('a');
    $cache->set('a', 1);
    $cache->add('a', 1);
    $cache->replace('a', 1);
    $cache->delete('a');
    $cache->increment('a');
    $cache->getMany(['a']);
    $cache->flush();
    $cache->flushGroup('default');

    expect($store->calls)->toBe(1);
});

it('degrades on each kind of operation', function (string $operation): void {
    $store = new UnavailableStore;
    $cache = Caches::make($store);

    match ($operation) {
        'get' => $cache->get('k'),
        'getMany' => $cache->getMany(['k']),
        'set' => $cache->set('k', 1),
        'add' => $cache->add('k', 1),
        'replace' => $cache->replace('k', 1),
        'delete' => $cache->delete('k'),
        'increment' => $cache->increment('k'),
        'flush' => $cache->flush(),
        'flushGroup' => $cache->flushGroup('g'),
    };

    expect($cache->isDegraded())->toBeTrue()
        ->and($store->calls)->toBe(1);
})->with(['get', 'getMany', 'set', 'add', 'replace', 'delete', 'increment', 'flush', 'flushGroup']);

it('falls back to what the request cache already holds when a forced read fails', function (): void {
    $runtime = new InMemoryCacheStore;
    $runtime->set('site:default:k', 'local', 0);

    expect(Caches::make(new UnavailableStore, runtime: $runtime)->get('k', 'default', true)->value)->toBe('local')
        ->and(Caches::make(new UnavailableStore, runtime: $runtime)->getMany(['k'], 'default', true)['k']->value)->toBe('local');
});

it('keeps for the whole request what it added or replaced while the store was down', function (): void {
    $clock = new FrozenClock;
    $cache = Caches::make(new UnavailableStore, runtime: new InMemoryCacheStore($clock));

    $cache->add('a', 1, 'default', 30);
    $cache->add('b', 1);
    $cache->replace('b', 2, 'default', 30);
    $cache->set('c', 1);
    $cache->increment('c', 4);
    $clock->advance(86_400);

    expect($cache->get('a')->value)->toBe(1)
        ->and($cache->get('b')->value)->toBe(2)
        ->and($cache->get('c')->value)->toBe(5);
});

it('does not touch the store for a non-persistent group', function (): void {
    $store = new UnavailableStore;
    $cache = Caches::make($store);
    $cache->addNonPersistentGroups('counts');

    $cache->set('k', 1, 'counts');
    $cache->get('k', 'counts');
    $cache->flushGroup('counts');

    expect($store->calls)->toBe(0)
        ->and($cache->isDegraded())->toBeFalse();
});

it('tries the store again once the request cache is flushed', function (): void {
    $store = new UnavailableStore;
    $cache = Caches::make($store);

    $cache->get('k');

    expect($cache->isDegraded())->toBeTrue();

    $cache->flushRuntime();

    expect($cache->isDegraded())->toBeFalse()
        ->and($cache->failure())->toBeNull();

    $cache->get('k');

    expect($store->calls)->toBe(2)
        ->and($cache->isDegraded())->toBeTrue();
});
