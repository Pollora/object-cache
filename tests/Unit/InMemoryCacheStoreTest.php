<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Clock\SystemClock;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Tests\Support\FrozenClock;

it('gives out a copy of an object on every read', function (): void {
    $store = new InMemoryCacheStore;
    $store->set('k', new ArrayObject([1]), 0);

    $first = $store->get('k')->value;
    $second = $store->get('k')->value;
    $viaMany = $store->getMany(['k'])['k']->value;

    expect($first)->not->toBe($second)
        ->and($viaMany)->not->toBe($first);
});

it('expires entries by the clock it is given', function (): void {
    $clock = new FrozenClock;
    $store = new InMemoryCacheStore($clock);
    $store->set('k', 'v', 5);

    $clock->advance(4);
    expect($store->get('k'))->not->toBeNull();

    $clock->advance(1);
    expect($store->get('k'))->toBeNull();
});

it('uses the server clock by default', function (): void {
    $store = new InMemoryCacheStore;
    $store->set('short', 'v', 100);
    $store->set('expired', 'v', -1);

    expect($store->get('short'))->not->toBeNull();
});

it('counts only live entries when it flushes a prefix', function (): void {
    $clock = new FrozenClock;
    $store = new InMemoryCacheStore($clock);
    $store->set('p:live', 1, 0);
    $store->set('p:old', 1, 5);
    $clock->advance(5);

    expect($store->deleteByPrefix('p:'))->toBe(1)
        ->and($store->get('p:old'))->toBeNull();
});

it('reports the time of the server', function (): void {
    $before = time();
    $now = (new SystemClock)->now();

    expect($now)->toBeGreaterThanOrEqual($before)
        ->and($now)->toBeLessThanOrEqual(time());
});

it('moves a frozen clock only when told to', function (): void {
    $clock = new FrozenClock(100);

    expect($clock->now())->toBe(100);

    $clock->advance(5);

    expect($clock->now())->toBe(105);
});

it('floors a number at zero, and keeps a result of exactly minus one out', function (): void {
    $store = new InMemoryCacheStore;
    $store->set('n', 3, 0);

    expect($store->adjust('n', -4))->toBe(0)
        ->and($store->get('n')?->value)->toBe(0);
});
