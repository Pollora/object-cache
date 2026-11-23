<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Infrastructure\Redis\RedisCacheStore;
use Pollora\ObjectCache\Tests\Support\FailingRedis;
use Pollora\ObjectCache\Tests\Support\FixedConnector;

/*
 * A Redis that cannot be reached is an ordinary event, not a crash: every
 * command reports it as StoreUnavailableException, quickly.
 */
beforeEach(function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('The redis extension is not loaded.');
    }
});

it('reports a Redis that refuses the connection', function (string $operation): void {
    $store = new RedisCacheStore(new PhpRedisConnector(['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.2]));

    $call = match ($operation) {
        'get' => fn () => $store->get('k'),
        'getMany' => fn () => $store->getMany(['k']),
        'set' => fn () => $store->set('k', 1, 0),
        'add' => fn () => $store->add('k', 1, 0),
        'replace' => fn () => $store->replace('k', 1, 0),
        'delete' => fn () => $store->delete('k'),
        'adjust' => fn () => $store->adjust('k', 1),
        'deleteByPrefix' => fn () => $store->deleteByPrefix('p:'),
    };

    expect($call)->toThrow(StoreUnavailableException::class);
})->with(['get', 'getMany', 'set', 'add', 'replace', 'delete', 'adjust', 'deleteByPrefix']);

it('gives up on an address that never answers within the timeout', function (): void {
    $store = new RedisCacheStore(new PhpRedisConnector(['host' => '10.255.255.1', 'connect_timeout' => 0.2, 'read_timeout' => 0.2]));
    $start = microtime(true);

    expect(fn () => $store->get('k'))->toThrow(StoreUnavailableException::class)
        ->and(microtime(true) - $start)->toBeLessThan(2.0);
});

it('does not connect until the first command', function (): void {
    new RedisCacheStore(new PhpRedisConnector(['host' => '10.255.255.1', 'connect_timeout' => 5.0]));

    expect(true)->toBeTrue();
});

it('refuses a flush with no prefix before touching Redis', function (): void {
    $store = new RedisCacheStore(new PhpRedisConnector(['port' => 1]));

    expect(fn () => $store->deleteByPrefix(''))->toThrow(InvalidArgumentException::class);
});

it('reads an error that phpredis reports without throwing', function (): void {
    $redis = new FailingRedis;
    $store = new RedisCacheStore(new FixedConnector($redis));

    expect(fn () => $store->get('k'))->toThrow(StoreUnavailableException::class, 'OOM command not allowed')
        ->and($redis->errorsCleared)->toBe(1);
});

it('takes a false answer with no error for a miss', function (): void {
    $redis = new FailingRedis;
    $redis->error = null;

    expect((new RedisCacheStore(new FixedConnector($redis)))->get('k'))->toBeNull();
});
