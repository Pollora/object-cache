<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\WordPress\EngineBuilder;
use Pollora\ObjectCache\Infrastructure\WordPress\Environment;
use Pollora\ObjectCache\Tests\Support\Environments;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

function engineSettings(): Settings
{
    return Settings::fromArray([
        'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => RedisHarness::database()],
        'prefix' => 'engine-test',
        'prefetch' => ['enabled' => true],
    ]);
}

it('prefixes the keys with the configured prefix, else the salt, else a hash of the database, the host and the table prefix', function (): void {
    $hash = 'wp-'.substr(sha1('db|dbhost|wp_'), 0, 12);

    expect(EngineBuilder::prefix(engineSettings(), Environments::make(salt: 'salt')))->toBe('engine-test')
        ->and(EngineBuilder::prefix(Settings::fromArray([]), Environments::make(salt: 'salt')))->toBe('salt')
        ->and(EngineBuilder::prefix(Settings::fromArray([]), Environments::make()))->toBe($hash)
        ->and(EngineBuilder::prefix(Settings::fromArray([]), Environments::make(database: 'other')))->not->toBe($hash)
        ->and(EngineBuilder::prefix(Settings::fromArray([]), Environments::make(host: 'other')))->not->toBe($hash)
        ->and(EngineBuilder::prefix(Settings::fromArray([]), Environments::make(tablePrefix: 'x_')))->not->toBe($hash);
});

/**
 * Whether the profile of a kind of page was written, for a request of this environment.
 */
function profileOf(string $signature, Environment $env, bool $request = true): bool
{
    $store = (new RedisHarness)->store();
    $cache = EngineBuilder::build(engineSettings(), $env, $request);
    $cache->set('1', 'v', 'posts');
    $cache->get('1', 'posts');
    $cache->savePrefetch(true);

    return $store->get((new KeyBuilder('engine-test'))->key('object-cache-prefetch', $signature, true, 0)) !== null;
}

it('lets a page of the front take part in the prefetch, under its blog and its visitor', function (): void {
    expect(profileOf('front:1:shop', Environments::make()))->toBeTrue()
        ->and(profileOf('front:3:shop', Environments::make(blogId: 3)))->toBeTrue()
        ->and(profileOf('front:1:shop:member', Environments::make(cookies: ['wordpress_logged_in_x' => '1'])))->toBeTrue();
});

it('keeps out of the prefetch a console command, a request that is not a page, and what declared itself dynamic', function (): void {
    expect(profileOf('front:1:shop', Environments::make(), request: false))->toBeFalse()
        ->and(profileOf('front:1:shop', Environments::make(dynamic: true)))->toBeFalse()
        ->and(profileOf('front:1:shop', Environments::make(sapi: 'cli')))->toBeFalse()
        ->and(profileOf('front:1:shop', Environments::make(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/shop'])))->toBeFalse();
});

it('keys the entries by blog in a network, and not in a single site', function (): void {
    $store = (new RedisHarness)->store();
    EngineBuilder::build(engineSettings(), Environments::make(multisite: true, blogId: 3))->set('k', 'v', 'posts');
    EngineBuilder::build(engineSettings(), Environments::make())->set('k', 'w', 'posts');

    expect($store->get((new KeyBuilder('engine-test', true))->key('posts', 'k', false, 3))?->value)->toBe('v')
        ->and($store->get((new KeyBuilder('engine-test'))->key('posts', 'k', false, 1))?->value)->toBe('w');
});
