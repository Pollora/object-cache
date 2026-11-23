<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Support\Facades\Cache;
use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

beforeEach(function (): void {
    (new RedisHarness)->store();

    config([
        'cache.default' => 'array',
        'cache.stores.array' => ['driver' => 'array'],
        'cache.stores.wordpress' => ['driver' => 'wordpress'],
    ]);
});

it('offers a wordpress cache driver that shares keys with the object cache', function (): void {
    $store = Cache::store('wordpress');
    $store->put('greeting', 'hello', 60);

    expect($store->get('greeting'))->toBe('hello')
        ->and($this->app->make(ObjectCache::class)->get('greeting', 'laravel', true)?->value)->toBe('hello');

    $store->flush();
});

it('writes to the group the configuration names', function (): void {
    config(['pollora.object-cache.laravel.group' => 'app-cache']);
    Cache::store('wordpress')->put('k', 'v', 60);

    expect($this->app->make(ObjectCache::class)->get('k', 'app-cache', true)?->value)->toBe('v');

    Cache::store('wordpress')->flush();
});

it('empties the object cache when cache:clear empties another store', function (): void {
    $cache = $this->app->make(ObjectCache::class);
    $cache->set('post', 'p', 'posts');

    $this->artisan('cache:clear')->assertExitCode(0);

    expect($this->app->make(ObjectCache::class)->get('post', 'posts', true))->toBeNull();
});

it('leaves the object cache alone when told not to follow cache:clear', function (): void {
    config(['pollora.object-cache.laravel.flush_with_cache_clear' => false]);
    $cache = $this->app->make(ObjectCache::class);
    $cache->set('post', 'p', 'posts');

    $this->artisan('cache:clear')->assertExitCode(0);

    expect($this->app->make(ObjectCache::class)->get('post', 'posts', true)?->value)->toBe('p');

    $cache->flush();
});

it('empties only its own group when cache:clear targets the wordpress store', function (): void {
    $cache = $this->app->make(ObjectCache::class);
    $cache->set('post', 'p', 'posts');
    Cache::store('wordpress')->put('k', 'v', 60);

    $this->artisan('cache:clear', ['store' => 'wordpress'])->assertExitCode(0);

    expect($this->app->make(ObjectCache::class)->get('post', 'posts', true)?->value)->toBe('p')
        ->and(Cache::store('wordpress')->get('k'))->toBeNull();

    $cache->flush();
});

it('does the same when the wordpress store is the default one', function (): void {
    config(['cache.default' => 'wordpress']);
    $cache = $this->app->make(ObjectCache::class);
    $cache->set('post', 'p', 'posts');

    $this->artisan('cache:clear')->assertExitCode(0);

    expect($this->app->make(ObjectCache::class)->get('post', 'posts', true)?->value)->toBe('p');

    $cache->flush();
});

it('empties the request cache of the running process along with cache:clear', function (): void {
    Functions\expect('wp_cache_flush_runtime')->once()->andReturn(true);

    $this->artisan('cache:clear')->assertExitCode(0);
});
