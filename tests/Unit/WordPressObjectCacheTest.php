<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use Pollora\ObjectCache\Tests\Support\Caches;

/*
 * The facade keeps WP_Object_Cache's signatures: `false` for a miss and an out
 * parameter for "found", an empty group for `default`, `_doing_it_wrong()` for
 * an invalid key.
 */
beforeEach(function (): void {
    Functions\when('wp_suspend_cache_addition')->justReturn(false);
    Functions\when('_doing_it_wrong')->justReturn(null);

    $this->cache = new WordPressObjectCache(Caches::make(new InMemoryCacheStore));
});

it('returns false for a miss and says so through found', function (): void {
    expect($this->cache->get('absent', 'group', false, $found))->toBeFalse()
        ->and($found)->toBeFalse();
});

it('tells a stored false from a miss', function (): void {
    $this->cache->set('flag', false, 'group');

    expect($this->cache->get('flag', 'group', false, $found))->toBeFalse()
        ->and($found)->toBeTrue();
});

it('stores and reads any value', function (): void {
    $this->cache->set('k', ['a' => 1], 'group', 60);

    expect($this->cache->get('k', 'group'))->toBe(['a' => 1]);
});

it('treats an empty or missing group as the default group', function (mixed $group): void {
    $this->cache->set('k', 'v', $group);

    expect($this->cache->get('k', 'default'))->toBe('v');
})->with(['empty' => [''], 'null' => [null], 'an array' => [[]]]);

it('accepts a group given as a number', function (): void {
    $this->cache->set('k', 'v', 7);

    expect($this->cache->get('k', '7'))->toBe('v');
});

it('adds, replaces and deletes', function (): void {
    expect($this->cache->add('k', 1, 'g'))->toBeTrue()
        ->and($this->cache->add('k', 2, 'g'))->toBeFalse()
        ->and($this->cache->replace('k', 3, 'g'))->toBeTrue()
        ->and($this->cache->replace('missing', 3, 'g'))->toBeFalse()
        ->and($this->cache->delete('k', 'g'))->toBeTrue()
        ->and($this->cache->delete('k', 'g'))->toBeFalse();
});

it('does not add while additions are suspended', function (): void {
    Functions\when('wp_suspend_cache_addition')->justReturn(true);

    expect($this->cache->add('k', 1))->toBeFalse()
        ->and($this->cache->get('k'))->toBeFalse();
});

it('reports an invalid key the way WordPress does, and refuses it', function (mixed $key, string $message): void {
    $reports = [];
    Functions\when('_doing_it_wrong')->alias(function (string $function, string $text, string $version) use (&$reports): void {
        $reports[] = [$function, $text, $version];
    });

    expect($this->cache->set($key, 'v'))->toBeFalse()
        ->and($reports)->toBe([[WordPressObjectCache::class.'::set', $message, '6.1.0']]);
})->with([
    'an empty string' => ['', 'Cache key must not be an empty string.'],
    'null' => [null, 'Cache key must be an integer or a non-empty string, NULL given.'],
    'an array' => [[], 'Cache key must be an integer or a non-empty string, array given.'],
]);

it('refuses an invalid key on every operation', function (): void {
    expect($this->cache->add('', 1))->toBeFalse()
        ->and($this->cache->replace('', 1))->toBeFalse()
        ->and($this->cache->get('', 'g', false, $found))->toBeFalse()
        ->and($found)->toBeFalse()
        ->and($this->cache->delete(''))->toBeFalse()
        ->and($this->cache->incr(''))->toBeFalse()
        ->and($this->cache->decr(''))->toBeFalse();
});

it('adds, sets, reads and deletes several keys', function (): void {
    expect($this->cache->set_multiple(['a' => 1, 'b' => 2], 'g'))->toBe(['a' => true, 'b' => true])
        ->and($this->cache->add_multiple(['b' => 9, 'c' => 3], 'g'))->toBe(['b' => false, 'c' => true])
        ->and($this->cache->get_multiple(['a', 'b', 'c', 'd'], 'g'))->toBe(['a' => 1, 'b' => 2, 'c' => 3, 'd' => false])
        ->and($this->cache->delete_multiple(['a', 'd'], 'g'))->toBe(['a' => true, 'd' => false]);
});

it('counts hits and misses', function (): void {
    $this->cache->set('k', 'v');

    $this->cache->get('k');
    $this->cache->get('k');
    $this->cache->get('absent');
    $this->cache->get_multiple(['k', 'absent']);

    expect($this->cache->cache_hits)->toBe(3)
        ->and($this->cache->cache_misses)->toBe(2);
});

it('prints the stats of the request', function (): void {
    $this->cache->set('k', 'v');
    $this->cache->get('k');

    $this->expectOutputString('<p><strong>Cache Hits:</strong> 1<br /><strong>Cache Misses:</strong> 0</p>');

    $this->cache->stats();
});

it('increments and decrements', function (): void {
    $this->cache->set('n', 5, 'g');

    expect($this->cache->incr('n', 3, 'g'))->toBe(8)
        ->and($this->cache->decr('n', '2', 'g'))->toBe(6)
        ->and($this->cache->incr('missing'))->toBeFalse();
});

it('flushes a group, the site, and the request cache', function (): void {
    $store = new InMemoryCacheStore;
    $cache = new WordPressObjectCache(Caches::make($store));
    $other = new WordPressObjectCache(Caches::make($store));

    $cache->set('a', 1, 'posts');
    $cache->set('b', 2, 'terms');

    expect($cache->flush_group('posts'))->toBeTrue()
        ->and($cache->get('a', 'posts'))->toBeFalse()
        ->and($cache->get('b', 'terms'))->toBe(2);

    $other->set('k', 'new', 'terms');

    expect($cache->flush_runtime())->toBeTrue()
        ->and($cache->get('k', 'terms'))->toBe('new')
        ->and($cache->flush())->toBeTrue()
        ->and($cache->get('b', 'terms'))->toBeFalse();
});

it('declares global and non-persistent groups, one name or a list', function (): void {
    $store = new InMemoryCacheStore;
    $cache = new WordPressObjectCache(Caches::make($store, multisite: true));
    $other = new WordPressObjectCache(Caches::make($store, multisite: true));

    $cache->add_global_groups('users');
    $cache->add_global_groups(['sites', 7, ['nested']]);
    $cache->add_non_persistent_groups('counts');
    $cache->add_non_persistent_groups(['plugins']);
    $other->add_global_groups('users');

    $cache->set('k', 'v', 'users');
    $cache->set('k', 'v', 'counts');

    expect($other->get('k', 'users'))->toBe('v')
        ->and($other->get('k', 'counts'))->toBeFalse();
});

it('switches blog', function (): void {
    $cache = new WordPressObjectCache(Caches::make(new InMemoryCacheStore, multisite: true));

    $cache->set('k', 'one', 'g');
    $cache->switch_to_blog('2');

    expect($cache->get('k', 'g'))->toBeFalse();
});

it('says which features of the object cache API it supports', function (string $feature, bool $supported): void {
    expect($this->cache->supports($feature))->toBe($supported);
})->with([
    ['add_multiple', true],
    ['set_multiple', true],
    ['get_multiple', true],
    ['delete_multiple', true],
    ['flush_runtime', true],
    ['flush_group', true],
    ['something_else', false],
]);

it('warns that reset() is deprecated, and empties the request cache', function (): void {
    Functions\expect('_deprecated_function')->once()->with('reset', '3.5.0', 'WP_Object_Cache::switch_to_blog()');
    $store = new InMemoryCacheStore;
    $cache = new WordPressObjectCache(Caches::make($store));
    $other = new WordPressObjectCache(Caches::make($store));
    $cache->set('k', 'old');
    $other->set('k', 'new');

    $cache->reset();

    expect($cache->get('k'))->toBe('new');
});

it('exposes the engine behind it', function (): void {
    expect($this->cache->engine()->isDegraded())->toBeFalse();
});
