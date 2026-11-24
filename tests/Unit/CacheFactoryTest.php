<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Infrastructure\Config\CacheFactory;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

/**
 * Settings that point at the Redis of the tests.
 *
 * @param  array<string, mixed>  $prefetch
 */
function factorySettings(array $prefetch): Settings
{
    return Settings::fromArray([
        'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => RedisHarness::database()],
        'prefetch' => $prefetch,
    ]);
}

/**
 * Whether the profile of a kind of page is stored, after one request read a key.
 */
function profileWritten(Settings $settings, ?string $kind): bool
{
    $store = (new RedisHarness)->store();
    $cache = CacheFactory::make($settings, 'factory-test', requestKind: $kind);
    $cache->set('1', 'v', 'posts');
    $cache->get('1', 'posts');
    $cache->savePrefetch(true);

    return $store->get((new KeyBuilder('factory-test'))->key('object-cache-prefetch', 'front:1:shop', true, 0)) !== null;
}

it('writes a profile when the prefetch is on and the request has a kind', function (): void {
    expect(profileWritten(factorySettings(['enabled' => true, 'max_keys' => 10, 'ttl' => 60]), 'front:1:shop'))->toBeTrue();
});

it('writes none when the prefetch is off, the request has no kind, or the profile has no room', function (): void {
    expect(profileWritten(factorySettings(['enabled' => false]), 'front:1:shop'))->toBeFalse()
        ->and(profileWritten(factorySettings(['enabled' => true]), null))->toBeFalse()
        ->and(profileWritten(factorySettings(['enabled' => true, 'max_keys' => 0]), 'front:1:shop'))->toBeFalse();
});

it('keeps a profile of one key when that is the limit', function (): void {
    expect(profileWritten(factorySettings(['enabled' => true, 'max_keys' => 1]), 'front:1:shop'))->toBeTrue();
});

it('records at most 300 keys by default, and keeps the profile for a week', function (): void {
    $store = (new RedisHarness)->store();
    $writer = CacheFactory::make(factorySettings(['enabled' => false]), 'factory-test');

    for ($i = 1; $i <= 301; $i++) {
        $writer->set((string) $i, 'v', 'posts');
    }

    $cache = CacheFactory::make(factorySettings(['enabled' => true]), 'factory-test', requestKind: 'front:1:shop');

    for ($i = 1; $i <= 301; $i++) {
        $cache->get((string) $i, 'posts');
    }

    $cache->savePrefetch(true);
    $key = (new KeyBuilder('factory-test'))->key('object-cache-prefetch', 'front:1:shop', true, 0);

    expect($store->get($key)?->value['e'])->toHaveCount(300)
        ->and(RedisHarness::connector()->connect()->ttl($key))->toBe(604800);
});

it('registers the groups of the settings', function (): void {
    (new RedisHarness)->store();
    $settings = Settings::fromArray([
        'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => RedisHarness::database()],
        'global_groups' => ['shared'], 'non_persistent_groups' => ['local'],
    ]);
    $cache = CacheFactory::make($settings, 'p', multisite: true);
    $cache->set('k', 'v', 'shared');
    $cache->set('k', 'v', 'local');
    $cache->switchToBlog(2);

    expect($cache->get('k', 'shared')?->value)->toBe('v');

    $other = CacheFactory::make($settings, 'p', multisite: true);

    expect($other->get('k', 'local'))->toBeNull();
});
