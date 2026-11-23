<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Tests\Support\InMemoryHarness;
use Pollora\ObjectCache\Tests\Support\StoreHarness;

/*
 * The contract of CacheStore, run on every adapter. A new adapter adds its
 * harness to this dataset and inherits every case.
 */
dataset('store harnesses', [
    'in-memory' => fn () => new InMemoryHarness,
]);

dataset('round-trip values', [
    'int' => [5],
    'zero' => [0],
    'negative int' => [-7],
    'float' => [1.5],
    'string' => ['hello'],
    'numeric string' => ['5'],
    'zero string' => ['0'],
    'empty string' => [''],
    'false' => [false],
    'null' => [null],
    'true' => [true],
    'nested array' => [['a' => [1, 2, ['b' => null]], 'c' => false]],
]);

it('returns a value exactly as it was stored', function (mixed $value, StoreHarness $harness): void {
    $store = $harness->store();

    $store->set('k', $value, 0);

    $found = $store->get('k');

    expect($found)->toBeInstanceOf(CacheValue::class)
        ->and($found->value)->toBe($value);
})->with('round-trip values')->with('store harnesses');

it('returns an equal object, not the stored instance', function (StoreHarness $harness): void {
    $store = $harness->store();
    $object = new stdClass;
    $object->name = 'cache';

    $store->set('k', $object, 0);
    $object->name = 'changed';

    $found = $store->get('k')->value;

    expect($found)->toEqual((object) ['name' => 'cache'])
        ->and($found)->not->toBe($object);
})->with('store harnesses');

it('tells a miss from a stored false', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('stored', false, 0);

    expect($store->get('missing'))->toBeNull()
        ->and($store->get('stored'))->toBeInstanceOf(CacheValue::class);
})->with('store harnesses');

it('reads several entries and leaves out the missing ones', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('a', 1, 0);
    $store->set('b', null, 0);

    $found = $store->getMany(['a', 'b', 'c']);

    expect(array_keys($found))->toBe(['a', 'b'])
        ->and($found['a']->value)->toBe(1)
        ->and($found['b']->value)->toBeNull();
})->with('store harnesses');

it('overwrites on set', function (StoreHarness $harness): void {
    $store = $harness->store();

    expect($store->set('k', 'one', 0))->toBeTrue()
        ->and($store->set('k', 'two', 0))->toBeTrue()
        ->and($store->get('k')->value)->toBe('two');
})->with('store harnesses');

it('adds only a key that is absent', function (StoreHarness $harness): void {
    $store = $harness->store();

    expect($store->add('k', 'first', 0))->toBeTrue()
        ->and($store->add('k', 'second', 0))->toBeFalse()
        ->and($store->get('k')->value)->toBe('first');
})->with('store harnesses');

it('replaces only a key that exists', function (StoreHarness $harness): void {
    $store = $harness->store();

    expect($store->replace('k', 'value', 0))->toBeFalse()
        ->and($store->get('k'))->toBeNull();

    $store->set('k', 'old', 0);

    expect($store->replace('k', 'new', 0))->toBeTrue()
        ->and($store->get('k')->value)->toBe('new');
})->with('store harnesses');

it('deletes an entry and says whether there was one', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('k', 'v', 0);

    expect($store->delete('k'))->toBeTrue()
        ->and($store->get('k'))->toBeNull()
        ->and($store->delete('k'))->toBeFalse();
})->with('store harnesses');

it('lets an entry expire after its time to live', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('short', 'v', 10);
    $store->set('forever', 'v', 0);

    $store->set('one', 'v', 1);

    $harness->advance(1);
    expect($store->get('one'))->toBeNull();

    $harness->advance(8);
    expect($store->get('short'))->not->toBeNull();

    $harness->advance(1);
    expect($store->get('short'))->toBeNull()
        ->and($store->get('forever'))->not->toBeNull();

    $harness->advance(86_400);
    expect($store->get('forever'))->not->toBeNull();
})->with('store harnesses');

it('treats an expired key as absent for add and replace', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('k', 'old', 5);
    $harness->advance(5);

    expect($store->replace('k', 'new', 0))->toBeFalse()
        ->and($store->add('k', 'new', 0))->toBeTrue()
        ->and($store->get('k')->value)->toBe('new');
})->with('store harnesses');

it('adds a signed amount to a number', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('n', 5, 0);

    expect($store->adjust('n', 3))->toBe(8)
        ->and($store->adjust('n', -2))->toBe(6)
        ->and($store->get('n')->value)->toBe(6);
})->with('store harnesses');

it('never lets a number go below zero', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('n', 2, 0);

    expect($store->adjust('n', -10))->toBe(0)
        ->and($store->get('n')->value)->toBe(0);
})->with('store harnesses');

it('answers false when the number does not exist', function (StoreHarness $harness): void {
    expect($harness->store()->adjust('missing', 1))->toBeFalse();
})->with('store harnesses');

it('counts a value that is not numeric as zero', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('s', 'text', 0);

    expect($store->adjust('s', 4))->toBe(4);
})->with('store harnesses');

it('adds to a numeric string and to a float', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('s', '5', 0);
    $store->set('f', 1.5, 0);

    expect($store->adjust('s', 1))->toBe(6)
        ->and($store->adjust('f', 1))->toBe(2.5);
})->with('store harnesses');

it('keeps the expiry of a number it adjusts', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('n', 1, 10);

    $store->adjust('n', 1);
    $harness->advance(10);

    expect($store->get('n'))->toBeNull();
})->with('store harnesses');

it('removes the entries under a prefix and only those', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('site:a:1', 1, 0);
    $store->set('site:a:2', 2, 0);
    $store->set('site:b:1', 3, 0);
    $store->set('other:a:1', 4, 0);

    expect($store->deleteByPrefix('site:a:'))->toBe(2)
        ->and($store->get('site:a:1'))->toBeNull()
        ->and($store->get('site:b:1'))->not->toBeNull()
        ->and($store->get('other:a:1'))->not->toBeNull();
})->with('store harnesses');

it('refuses to flush an empty prefix', function (StoreHarness $harness): void {
    $store = $harness->store();
    $store->set('k', 'v', 0);

    expect(fn () => $store->deleteByPrefix(''))->toThrow(InvalidArgumentException::class)
        ->and($store->get('k'))->not->toBeNull();
})->with('store harnesses');
