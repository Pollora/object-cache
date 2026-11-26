<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

/*
 * The connector against a real Redis (skipped where there is none, a failure in
 * CI): which database it selects, what it switches off on the client, and what
 * it does with timeouts, sockets and passwords.
 */
function liveConfig(array $overrides = []): array
{
    return array_merge([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => RedisHarness::database(),
    ], $overrides);
}

beforeEach(function (): void {
    (new RedisHarness)->store();
});

it('connects, and selects the database', function (): void {
    $redis = (new PhpRedisConnector(liveConfig()))->connect();

    expect($redis->ping())->toBeTrue()
        ->and($redis->getDbNum())->toBe(RedisHarness::database());
});

it('selects database 0 when none is given', function (): void {
    $redis = (new PhpRedisConnector(liveConfig(['database' => null])))->connect();

    expect($redis->getDbNum())->toBe(0);
});

it('leaves the client with no serializer, no compression and no key prefix', function (): void {
    $redis = (new PhpRedisConnector(liveConfig()))->connect();

    expect($redis->getOption(Redis::OPT_SERIALIZER))->toBe(Redis::SERIALIZER_NONE)
        ->and($redis->getOption(Redis::OPT_PREFIX))->toBeEmpty()
        ->and($redis->getOption(Redis::OPT_COMPRESSION))->toBe(Redis::COMPRESSION_NONE)
        ->and($redis->getOption(Redis::OPT_SCAN))->toBe(Redis::SCAN_RETRY);

    if (defined('Redis::OPT_THROW_ON_ERROR')) {
        expect($redis->getOption(Redis::OPT_THROW_ON_ERROR))->toBeTrue();
    }
});

it('waits one second at most by default', function (): void {
    $redis = (new PhpRedisConnector(liveConfig()))->connect();

    expect($redis->getTimeout())->toBe(1.0)
        ->and($redis->getReadTimeout())->toBe(1.0);
});

it('takes the timeouts it is given', function (): void {
    $redis = (new PhpRedisConnector(liveConfig(['connect_timeout' => 2.5, 'read_timeout' => 3.5])))->connect();

    expect($redis->getTimeout())->toBe(2.5)
        ->and($redis->getReadTimeout())->toBe(3.5);
});

it('opens a persistent connection when asked to', function (): void {
    $redis = (new PhpRedisConnector(liveConfig(['persistent' => true])))->connect();

    expect($redis->ping())->toBeTrue();
});

it('prefers a Unix socket to the host and the port', function (): void {
    $connector = new PhpRedisConnector(liveConfig(['path' => '/nonexistent/redis.sock']));

    expect(fn () => $connector->connect())->toThrow(StoreUnavailableException::class);
});

it('sends the password, and the user name with it when there is one', function (array $credentials): void {
    $connector = new PhpRedisConnector(liveConfig($credentials));

    expect(fn () => $connector->connect())->toThrow(StoreUnavailableException::class);
})->with([
    'a password' => [['password' => 'secret']],
    'a user and a password' => [['username' => 'cache', 'password' => 'secret']],
]);

it('sends no credentials when there is no password', function (array $credentials): void {
    expect((new PhpRedisConnector(liveConfig($credentials)))->connect()->ping())->toBeTrue();
})->with([
    'none' => [[]],
    'null' => [['password' => null]],
    'empty' => [['password' => '']],
    'a user without a password' => [['username' => 'cache']],
]);

it('reports an address that refuses the connection', function (): void {
    $connector = new PhpRedisConnector(liveConfig(['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.2]));

    expect(fn () => $connector->connect())->toThrow(StoreUnavailableException::class);
});

it('gives AUTH the password alone, or the user and the password of an ACL user, or nothing', function (): void {
    expect(PhpRedisConnector::credentials(['password' => 'secret']))->toBe('secret')
        ->and(PhpRedisConnector::credentials(['password' => 'secret', 'username' => '']))->toBe('secret')
        ->and(PhpRedisConnector::credentials(['password' => 'secret', 'username' => 'cache']))->toBe(['cache', 'secret'])
        ->and(PhpRedisConnector::credentials(['password' => '', 'username' => 'cache']))->toBeNull()
        ->and(PhpRedisConnector::credentials(['username' => 'cache']))->toBeNull()
        ->and(PhpRedisConnector::credentials([]))->toBeNull();
});

it('reads a numeric credential as text, and one without a text form as missing', function (): void {
    expect(PhpRedisConnector::credentials(['password' => 1234, 'username' => 5]))->toBe(['5', '1234'])
        ->and(PhpRedisConnector::credentials(['password' => ['secret']]))->toBeNull()
        ->and(PhpRedisConnector::credentials(['password' => 'secret', 'username' => ['cache']]))->toBe('secret');
});
