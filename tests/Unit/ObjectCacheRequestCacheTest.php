<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Tests\Support\Caches;
use Pollora\ObjectCache\Tests\Support\FrozenClock;

/*
 * What the request cache keeps after a call. Each case empties the persistent
 * store behind the cache's back: only a copy kept in the request cache can still
 * answer, and it must stay for the whole request, whatever the time to live.
 */
beforeEach(function (): void {
    $this->clock = new FrozenClock;
    $this->persistent = new InMemoryCacheStore;
    $this->cache = Caches::make($this->persistent, runtime: new InMemoryCacheStore($this->clock));

    $this->wipePersistent = function (): void {
        $this->persistent->deleteByPrefix('site:');
        $this->clock->advance(86_400);
    };
});

it('keeps what it stored for the rest of the request', function (): void {
    $this->cache->set('k', 'v', 'default', 30);
    ($this->wipePersistent)();

    expect($this->cache->get('k')->value)->toBe('v');
});

it('keeps what it added for the rest of the request', function (): void {
    $this->cache->add('k', 'v', 'default', 30);
    ($this->wipePersistent)();

    expect($this->cache->get('k')->value)->toBe('v');
});

it('keeps what it replaced for the rest of the request', function (): void {
    $this->cache->set('k', 'old');
    $this->cache->replace('k', 'new', 'default', 30);
    ($this->wipePersistent)();

    expect($this->cache->get('k')->value)->toBe('new');
});

it('keeps the result of an increment for the rest of the request', function (): void {
    $this->cache->set('n', 1);
    $this->cache->increment('n', 4);
    ($this->wipePersistent)();

    expect($this->cache->get('n')->value)->toBe(5);
});

it('keeps what it read for the rest of the request', function (): void {
    $this->persistent->set('site:default:k', 'v', 30);

    $this->cache->get('k');
    ($this->wipePersistent)();

    expect($this->cache->get('k')->value)->toBe('v');
});

it('keeps what it read in bulk for the rest of the request', function (): void {
    $this->persistent->set('site:default:k', 'v', 30);

    $this->cache->getMany(['k']);
    ($this->wipePersistent)();

    expect($this->cache->get('k')->value)->toBe('v');
});

it('deletes a key that only the persistent store holds', function (): void {
    $this->persistent->set('site:default:k', 'v', 0);

    expect($this->cache->delete('k'))->toBeTrue()
        ->and($this->persistent->get('site:default:k'))->toBeNull();
});

it('deletes a key that only the request cache holds', function (): void {
    $this->cache->set('k', 'v');
    $this->persistent->deleteByPrefix('site:');

    expect($this->cache->delete('k'))->toBeTrue()
        ->and($this->cache->get('k'))->toBeNull();
});
