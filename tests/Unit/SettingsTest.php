<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Config\SitePrefix;

it('has a default for every key', function (): void {
    $settings = Settings::fromArray([]);

    expect($settings->redis)->toBe(['host' => '127.0.0.1', 'port' => 6379, 'database' => 0, 'connect_timeout' => 1.0, 'read_timeout' => 1.0, 'persistent' => false])
        ->and($settings->prefix)->toBeNull()
        ->and($settings->serializer)->toBe('php')
        ->and($settings->allowedClasses)->toBeTrue()
        ->and($settings->maxTtl)->toBe(0)
        ->and($settings->globalGroups)->toBe([])
        ->and($settings->nonPersistentGroups)->toBe([])
        ->and($settings->prefetchEnabled)->toBeFalse()
        ->and($settings->prefetchMaxKeys)->toBe(300)
        ->and($settings->prefetchTtl)->toBe(604800)
        ->and($settings->prefetchExcludeGroups)->toBe(['userlogins']);
});

it('reads every key, as text where WordPress configs give text', function (): void {
    $settings = Settings::fromArray([
        'redis' => ['host' => 'r.test', 'port' => '6380', 'username' => 'u', 'password' => 'p', 'database' => '3', 'connect_timeout' => '0.5', 'read_timeout' => '2', 'persistent' => 1],
        'prefix' => 'mine', 'serializer' => 'igbinary', 'allowed_classes' => ['A', 5, 'B'], 'max_ttl' => '60',
        'global_groups' => ['g', 7], 'non_persistent_groups' => ['n'],
        'prefetch' => ['enabled' => 1, 'max_keys' => '10', 'ttl' => '20', 'exclude_groups' => ['x', null]],
    ]);

    expect($settings->redis)->toBe(['host' => 'r.test', 'port' => 6380, 'username' => 'u', 'password' => 'p', 'database' => 3, 'connect_timeout' => 0.5, 'read_timeout' => 2.0, 'persistent' => true])
        ->and($settings->prefix)->toBe('mine')
        ->and($settings->serializer)->toBe('igbinary')
        ->and($settings->allowedClasses)->toBe(['A', 'B'])
        ->and($settings->maxTtl)->toBe(60)
        ->and($settings->globalGroups)->toBe(['g'])
        ->and($settings->nonPersistentGroups)->toBe(['n'])
        ->and($settings->prefetchEnabled)->toBeTrue()
        ->and($settings->prefetchMaxKeys)->toBe(10)
        ->and($settings->prefetchTtl)->toBe(20)
        ->and($settings->prefetchExcludeGroups)->toBe(['x']);
});

it('takes a blank prefix for none, and a boolean for allowed classes', function (): void {
    expect(Settings::fromArray(['prefix' => '  '])->prefix)->toBeNull()
        ->and(Settings::fromArray(['allowed_classes' => false])->allowedClasses)->toBeFalse()
        ->and(Settings::fromArray(['allowed_classes' => 0])->allowedClasses)->toBeFalse()
        ->and(Settings::fromArray(['global_groups' => 'text'])->globalGroups)->toBe([]);
});

it('uses a socket path instead of host and port when given one', function (): void {
    expect(Settings::fromArray(['redis' => ['path' => '/run/redis.sock']])->redis['path'])->toBe('/run/redis.sock');
});

it('reads a URL, and lets it override the other keys', function (): void {
    $redis = Settings::fromArray(['redis' => ['url' => 'redis://user%40x:p%3Ass@r.test:6390/4', 'host' => 'ignored.test', 'database' => 9]])->redis;

    expect($redis)->toMatchArray(['host' => 'r.test', 'port' => 6390, 'username' => 'user@x', 'password' => 'p:ss', 'database' => 4])
        ->and($redis['database'])->toBeInt();
});

it('ignores a URL it cannot read, and a database that is not a number', function (): void {
    expect(Settings::fromArray(['redis' => ['url' => 'http:///', 'host' => 'a.test']])->redis['host'])->toBe('a.test')
        ->and(Settings::fromArray(['redis' => ['url' => 'redis://r.test/abc', 'database' => 5]])->redis['database'])->toBe(5)
        ->and(Settings::fromArray(['redis' => ['url' => '']])->redis['host'])->toBe('127.0.0.1');
});

it('takes the configured prefix first, then the salt, then a hash of the site', function (): void {
    expect(SitePrefix::resolve('mine', 'salt', 'db', 'host', 'wp_'))->toBe('mine')
        ->and(SitePrefix::resolve(null, 'salt', 'db', 'host', 'wp_'))->toBe('salt')
        ->and(SitePrefix::resolve('  ', "  \t", 'db', 'host', 'wp_'))->toBe('wp-'.substr(sha1('db|host|wp_'), 0, 12))
        ->and(SitePrefix::resolve(null, null, '', '', ''))->toBe('wp-'.substr(sha1('||'), 0, 12))
        ->and(SitePrefix::resolve(null, null, 'd', '', ''))->toBe('wp-'.substr(sha1('d||'), 0, 12))
        ->and(SitePrefix::resolve(null, null, '', 'h', ''))->toBe('wp-'.substr(sha1('|h|'), 0, 12))
        ->and(SitePrefix::resolve(null, null, '', '', 'p_'))->toBe('wp-'.substr(sha1('||p_'), 0, 12));
});

it('reads a URL it cannot parse without a warning', function (): void {
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $host = Settings::fromArray(['redis' => ['url' => 'http:///', 'host' => 'a.test']])->redis['host'];
    } finally {
        restore_error_handler();
    }

    expect($host)->toBe('a.test');
});
