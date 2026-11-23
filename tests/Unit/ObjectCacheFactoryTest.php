<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Infrastructure\Laravel\ObjectCacheFactory;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

/**
 * The factory's configuration reaches the cache: tested through what lands in a real Redis.
 *
 * @param  array<string, mixed>  $prefetch
 */
function factoryConfig(array $prefetch): Repository
{
    return new Repository([
        'database' => ['redis' => ['default' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379)]]],
        'pollora' => ['object-cache' => [
            'prefix' => 'factory-test',
            'redis' => ['database' => RedisHarness::database()],
            'prefetch' => $prefetch,
        ]],
    ]);
}

/**
 * Whether the profile of a kind of page is stored, after one request read a key.
 */
function profileWritten(Repository $config, ?string $kind): bool
{
    $store = (new RedisHarness)->store();
    $cache = ObjectCacheFactory::make($config, requestKind: $kind);
    $cache->set('1', 'v', 'posts');
    $cache->get('1', 'posts');
    $cache->savePrefetch(true);

    $key = (new KeyBuilder('factory-test'))->key('object-cache-prefetch', 'front:1:shop', true, 0);

    return $store->get($key) !== null;
}

it('writes a profile when the prefetch is on and the request has a kind', function (): void {
    expect(profileWritten(factoryConfig(['enabled' => true, 'max_keys' => 10, 'ttl' => 60]), 'front:1:shop'))->toBeTrue();
});

it('writes none when the prefetch is off, the request has no kind, or the profile has no room', function (): void {
    expect(profileWritten(factoryConfig(['enabled' => false]), 'front:1:shop'))->toBeFalse()
        ->and(profileWritten(factoryConfig(['enabled' => true]), null))->toBeFalse()
        ->and(profileWritten(factoryConfig(['enabled' => true, 'max_keys' => 0]), 'front:1:shop'))->toBeFalse();
});

it('uses a default limit and lifetime when the configuration names none', function (): void {
    expect(profileWritten(factoryConfig(['enabled' => true]), 'front:1:shop'))->toBeTrue();
});
