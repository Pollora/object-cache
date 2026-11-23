<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Redis\RedisCacheStore;
use Pollora\ObjectCache\Tests\Support\CountingConnector;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

/*
 * What only the Redis store does: when it connects, how it flushes a long list
 * of keys, and what it makes of a value it did not write.
 */
beforeEach(function (): void {
    (new RedisHarness)->store();

    $this->connector = new CountingConnector(RedisHarness::connector());
    $this->store = new RedisCacheStore($this->connector);
    $this->raw = RedisHarness::connector()->connect();
});

it('connects on the first command, once', function (): void {
    expect($this->connector->connections)->toBe(0);

    $this->store->get('a');
    $this->store->set('a', 1, 0);
    $this->store->adjust('a', 1);

    expect($this->connector->connections)->toBe(1);
});

it('does not connect to read no keys', function (): void {
    expect($this->store->getMany([]))->toBe([])
        ->and($this->connector->connections)->toBe(0);
});

it('flushes more keys than one scan returns', function (): void {
    $pipeline = $this->raw->multi(Redis::PIPELINE);

    for ($i = 0; $i < 3000; $i++) {
        $pipeline->set("mine:{$i}", 's:x');
    }

    $pipeline->set('theirs:1', 's:x');
    $pipeline->exec();

    expect($this->store->deleteByPrefix('mine:'))->toBe(3000)
        ->and($this->raw->dbSize())->toBe(1)
        ->and($this->raw->get('theirs:1'))->toBe('s:x');
});

it('takes the characters of a glob in a prefix literally', function (): void {
    $this->raw->set('a*b:1', 's:x');
    $this->raw->set('axxb:1', 's:x');
    $this->raw->set('a[b]:1', 's:x');
    $this->raw->set('ab:1', 's:x');

    expect($this->store->deleteByPrefix('a*b:'))->toBe(1)
        ->and($this->store->deleteByPrefix('a[b]:'))->toBe(1)
        ->and($this->raw->exists('axxb:1', 'ab:1'))->toBe(2);
});

it('takes a value it cannot read for a miss', function (string $stored): void {
    $this->raw->set('k', $stored);
    $this->raw->set('ok', 's:fine');

    expect($this->store->get('k'))->toBeNull()
        ->and(array_keys($this->store->getMany(['k', 'ok'])))->toBe(['ok']);
})->with(['garbage' => ['junk'], 'cut short' => ['x:p:a:2:{']]);

it('keeps the time to live Redis was given', function (): void {
    $this->store->set('k', 'v', 100);

    expect($this->raw->ttl('k'))->toBeGreaterThan(95)->toBeLessThanOrEqual(100);

    $this->store->set('forever', 'v', 0);

    expect($this->raw->ttl('forever'))->toBe(-1);
});

it('counts a value another program wrote as 0', function (): void {
    $this->raw->set('k', 'plain text');

    expect($this->store->adjust('k', 3))->toBe(3);
});

it('keeps an add from overwriting, and a replace from creating', function (): void {
    $this->raw->set('k', 's:old');

    expect($this->store->add('k', 'new', 0))->toBeFalse()
        ->and($this->raw->get('k'))->toBe('s:old')
        ->and($this->store->replace('absent', 'new', 0))->toBeFalse()
        ->and($this->raw->exists('absent'))->toBe(0);
});

it('stores an integer as plain decimal text', function (): void {
    $this->store->set('k', 42, 0);

    expect($this->raw->get('k'))->toBe('42');
});

it('stores a lone float and the result of float arithmetic as text it can read back', function (): void {
    $this->store->set('k', 1.5, 0);
    $this->store->adjust('k', 1);

    expect($this->raw->get('k'))->toBe('d:2.5');
});

it('answers false for a key that is absent, without a warning', function (): void {
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $result = $this->store->adjust('absent', 1);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeFalse();
});
