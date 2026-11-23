<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchPlan;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Infrastructure\WordPress\DropIn;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use Pollora\ObjectCache\Tests\Support\Caches;
use Pollora\ObjectCache\Tests\Support\CountingStore;
use Pollora\ObjectCache\Tests\Support\UnavailableStore;

afterEach(function (): void {
    Container::setInstance(null);
});

function bindObjectCache(): Container
{
    $container = new Container;
    Container::setInstance($container);
    $container->bind(WordPressObjectCache::class, static fn (): WordPressObjectCache => new WordPressObjectCache(Caches::make(new InMemoryCacheStore)));

    return $container;
}

it('is not available when the package is not wired into the container', function (): void {
    Container::setInstance(new Container);

    expect(DropIn::available())->toBeFalse();
});

it('is available when phpredis is loaded and the container holds the cache', function (): void {
    bindObjectCache();

    expect(DropIn::available())->toBe(extension_loaded('redis'));
});

it('builds a fresh object cache from the container', function (): void {
    bindObjectCache();

    $first = DropIn::create();
    $second = DropIn::create();

    expect($first)->toBeInstanceOf(WordPressObjectCache::class)
        ->and($second)->not->toBe($first);
});

it('writes the failure of Redis to the error log, once the request is over', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'oc-log');
    $previous = ini_set('error_log', $log);
    $cache = new WordPressObjectCache(Caches::make(new UnavailableStore));
    $cache->get('k');

    DropIn::report($cache);
    ini_set('error_log', (string) $previous);

    expect(file_get_contents($log))->toContain('[pollora/object-cache] Redis unavailable, serving from the request cache: Redis is down.');

    unlink($log);
});

it('writes nothing when Redis did not fail', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'oc-log');
    $previous = ini_set('error_log', $log);

    DropIn::report(new WordPressObjectCache(Caches::make(new InMemoryCacheStore)));
    ini_set('error_log', (string) $previous);

    expect(file_get_contents($log))->toBe('');

    unlink($log);
});

it('reports a failure that is a StoreUnavailableException', function (): void {
    $cache = new WordPressObjectCache(Caches::make(new UnavailableStore));
    $cache->get('k');

    expect($cache->engine()->failure())->toBeInstanceOf(StoreUnavailableException::class);
});

it('saves the profile of a page answered with a 200, and only then', function (): void {
    $store = new CountingStore(new InMemoryCacheStore);
    Caches::make($store)->set('1', 'v', 'posts');
    $plan = new PrefetchPlan('front:1:shop', 10, 60);

    $notFound = new WordPressObjectCache(Caches::make($store, prefetch: $plan));
    $notFound->get('1', 'posts');
    DropIn::report($notFound, 404);
    $afterNotFound = $store->sets;

    $page = new WordPressObjectCache(Caches::make($store, prefetch: $plan));
    $page->get('1', 'posts');
    DropIn::report($page, 200);

    $withoutStatus = new WordPressObjectCache(Caches::make($store, prefetch: new PrefetchPlan('front:1:other', 10, 60)));
    $withoutStatus->get('1', 'posts');
    DropIn::report($withoutStatus);

    expect($afterNotFound)->toBe(1)
        ->and($store->sets)->toBe(2);
});
