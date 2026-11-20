<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\TtlPolicy;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Tests\Support\Caches;
use Pollora\ObjectCache\Tests\Support\InMemoryHarness;
use Pollora\ObjectCache\Tests\Support\StoreHarness;

/*
 * The behavior of WP_Object_Cache (wp-includes/class-wp-object-cache.php),
 * checked on the object cache with every persistent adapter behind it.
 */
dataset('persistent harnesses', [
    'in-memory' => fn () => new InMemoryHarness,
]);

it('returns what it was given, and tells a stored false from a miss', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    $cache->set('flag', false, 'options');

    expect($cache->get('flag', 'options'))->toBeInstanceOf(CacheValue::class)
        ->and($cache->get('flag', 'options')->value)->toBeFalse()
        ->and($cache->get('absent', 'options'))->toBeNull();
})->with('persistent harnesses');

it('keeps groups apart', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    $cache->set('id', 'post', 'posts');
    $cache->set('id', 'term', 'terms');

    expect($cache->get('id', 'posts')->value)->toBe('post')
        ->and($cache->get('id', 'terms')->value)->toBe('term');
})->with('persistent harnesses');

it('treats an empty group as the default group', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    $cache->set('k', 'v', '');

    expect($cache->get('k', 'default')->value)->toBe('v')
        ->and($cache->get('k')->value)->toBe('v');
})->with('persistent harnesses');

it('accepts an integer key and the same key as a string', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    $cache->set(42, 'answer');

    expect($cache->get('42')->value)->toBe('answer');
})->with('persistent harnesses');

it('refuses an invalid key everywhere', function (StoreHarness $harness, mixed $key): void {
    $cache = Caches::make($harness->store());

    expect($cache->set($key, 'v'))->toBeFalse()
        ->and($cache->add($key, 'v'))->toBeFalse()
        ->and($cache->replace($key, 'v'))->toBeFalse()
        ->and($cache->get($key))->toBeNull()
        ->and($cache->delete($key))->toBeFalse()
        ->and($cache->increment($key))->toBeFalse()
        ->and($cache->decrement($key))->toBeFalse();
})->with('persistent harnesses')->with([
    'empty string' => [''],
    'blank string' => ['   '],
    'null' => [null],
    'float' => [1.5],
    'array' => [[]],
]);

it('adds only a key that is not taken', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    expect($cache->add('k', 'first'))->toBeTrue()
        ->and($cache->add('k', 'second'))->toBeFalse()
        ->and($cache->get('k')->value)->toBe('first');
})->with('persistent harnesses');

it('replaces only a key that exists', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    expect($cache->replace('k', 'v'))->toBeFalse()
        ->and($cache->get('k'))->toBeNull();

    $cache->set('k', 'old');

    expect($cache->replace('k', 'new'))->toBeTrue()
        ->and($cache->get('k')->value)->toBe('new');
})->with('persistent harnesses');

it('deletes a key and says whether it existed', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->set('k', 'v');

    expect($cache->delete('k'))->toBeTrue()
        ->and($cache->get('k'))->toBeNull()
        ->and($cache->delete('k'))->toBeFalse();
})->with('persistent harnesses');

it('increments and decrements a number', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->set('n', 5);

    expect($cache->increment('n'))->toBe(6)
        ->and($cache->increment('n', 4))->toBe(10)
        ->and($cache->decrement('n', 3))->toBe(7)
        ->and($cache->get('n')->value)->toBe(7);
})->with('persistent harnesses');

it('never lets a number go below zero', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->set('n', 2);

    expect($cache->decrement('n', 10))->toBe(0)
        ->and($cache->increment('n', -5))->toBe(0);
})->with('persistent harnesses');

it('does not create a number that is missing', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    expect($cache->increment('n'))->toBeFalse()
        ->and($cache->decrement('n'))->toBeFalse()
        ->and($cache->get('n'))->toBeNull();
})->with('persistent harnesses');

it('counts a value that is not numeric as zero', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->set('s', 'text');

    expect($cache->increment('s', 3))->toBe(3);
})->with('persistent harnesses');

it('forgets a number that another worker has deleted', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $cache->set('n', 5);

    $persistent->deleteByPrefix('site:');

    expect($cache->increment('n'))->toBeFalse()
        ->and($cache->get('n'))->toBeNull();
})->with('persistent harnesses');

it('hands out a copy of an object, never the object itself', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $object = new stdClass;
    $object->name = 'original';

    $cache->set('o', $object);
    $object->name = 'changed';

    $first = $cache->get('o')->value;
    $first->name = 'mutated';

    expect($cache->get('o')->value->name)->toBe('original');
})->with('persistent harnesses');

it('reads, stores, adds and deletes several keys', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    expect($cache->setMany(['a' => 1, 'b' => 2], 'g'))->toBe(['a' => true, 'b' => true])
        ->and($cache->addMany(['b' => 9, 'c' => 3], 'g'))->toBe(['b' => false, 'c' => true]);

    $found = $cache->getMany(['a', 'b', 'c', 'd'], 'g');

    expect(array_map(fn (?CacheValue $value) => $value?->value, $found))->toBe(['a' => 1, 'b' => 2, 'c' => 3, 'd' => null])
        ->and($cache->deleteMany(['a', 'd'], 'g'))->toBe(['a' => true, 'd' => false])
        ->and($cache->get('a', 'g'))->toBeNull();
})->with('persistent harnesses');

it('leaves out what is neither an integer nor a string among several keys', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());

    expect($cache->getMany([1.5, null, ['a'], true]))->toBe([])
        ->and($cache->deleteMany([1.5, null]))->toBe([]);
})->with('persistent harnesses');

it('answers null for an invalid key among several', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->set('a', 1, 'g');

    $found = $cache->getMany(['a', '', 7], 'g');

    expect($found['a']->value)->toBe(1)
        ->and($found[''])->toBeNull()
        ->and($found[7])->toBeNull();
})->with('persistent harnesses');

it('shares blog-specific groups per blog in a multisite', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store(), multisite: true);

    $cache->set('k', 'blog one', 'options');
    $cache->switchToBlog(2);

    expect($cache->get('k', 'options'))->toBeNull();

    $cache->set('k', 'blog two', 'options');
    $cache->switchToBlog(1);

    expect($cache->get('k', 'options')->value)->toBe('blog one');
})->with('persistent harnesses');

it('shares a global group across blogs', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store(), multisite: true);
    $cache->addGlobalGroups(['users']);

    $cache->set('k', 'v', 'users');
    $cache->switchToBlog(2);

    expect($cache->get('k', 'users')->value)->toBe('v');
})->with('persistent harnesses');

it('ignores the blog on a single site', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store(), multisite: false);

    $cache->set('k', 'v', 'options');
    $cache->switchToBlog(2);

    expect($cache->get('k', 'options')->value)->toBe('v');
})->with('persistent harnesses');

it('flushes one group in every blog and leaves the others', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store(), multisite: true);

    $cache->set('k', 'one', 'posts');
    $cache->set('k', 'terms', 'terms');
    $cache->switchToBlog(2);
    $cache->set('k', 'two', 'posts');

    expect($cache->flushGroup('posts'))->toBeTrue()
        ->and($cache->get('k', 'posts'))->toBeNull();

    $cache->switchToBlog(1);

    expect($cache->get('k', 'posts'))->toBeNull()
        ->and($cache->get('k', 'terms')->value)->toBe('terms');
})->with('persistent harnesses');

it('flushes everything of the site and nothing of the store beside it', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $persistent->set('someone-else:default:k', 'theirs', 0);
    $cache = Caches::make($persistent);

    $cache->set('a', 1);
    $cache->set('b', 2, 'posts');

    expect($cache->flush())->toBeTrue()
        ->and($cache->get('a'))->toBeNull()
        ->and($cache->get('b', 'posts'))->toBeNull()
        ->and($persistent->get('someone-else:default:k'))->not->toBeNull();
})->with('persistent harnesses');

it('shares values between two requests through the persistent store', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $first = Caches::make($persistent);
    $second = Caches::make($persistent);

    $first->set('k', 'v');

    expect($second->get('k')->value)->toBe('v');

    $first->delete('k');

    expect(Caches::make($persistent)->get('k'))->toBeNull();
})->with('persistent harnesses');

it('answers from the request cache, and rereads the store when forced', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $other = Caches::make($persistent);

    $cache->set('k', 'old');
    $other->set('k', 'new');

    expect($cache->get('k')->value)->toBe('old')
        ->and($cache->get('k', 'default', true)->value)->toBe('new')
        ->and($cache->get('k')->value)->toBe('new');
})->with('persistent harnesses');

it('rereads the store for several keys when forced', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $other = Caches::make($persistent);

    $cache->set('k', 'old');
    $other->set('k', 'new');

    expect($cache->getMany(['k'])['k']->value)->toBe('old')
        ->and($cache->getMany(['k'], 'default', true)['k']->value)->toBe('new');
})->with('persistent harnesses');

it('empties only the request cache on flush_runtime', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $other = Caches::make($persistent);

    $cache->set('k', 'old');
    $other->set('k', 'new');

    expect($cache->flushRuntime())->toBeTrue()
        ->and($cache->get('k')->value)->toBe('new');
})->with('persistent harnesses');

it('keeps a non-persistent group out of the persistent store', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $other = Caches::make($persistent);
    $cache->addNonPersistentGroups('counts');

    $cache->set('k', 'v', 'counts');

    expect($cache->get('k', 'counts')->value)->toBe('v')
        ->and($other->get('k', 'counts'))->toBeNull();
})->with('persistent harnesses');

it('handles every operation on a non-persistent group', function (StoreHarness $harness): void {
    $cache = Caches::make($harness->store());
    $cache->addNonPersistentGroups('counts');

    expect($cache->add('k', 1, 'counts'))->toBeTrue()
        ->and($cache->add('k', 2, 'counts'))->toBeFalse()
        ->and($cache->replace('k', 3, 'counts'))->toBeTrue()
        ->and($cache->replace('missing', 3, 'counts'))->toBeFalse()
        ->and($cache->increment('k', 2, 'counts'))->toBe(5)
        ->and($cache->decrement('k', 1, 'counts'))->toBe(4)
        ->and($cache->getMany(['k'], 'counts')['k']->value)->toBe(4)
        ->and($cache->delete('k', 'counts'))->toBeTrue()
        ->and($cache->delete('k', 'counts'))->toBeFalse()
        ->and($cache->flushGroup('counts'))->toBeTrue();
})->with('persistent harnesses');

it('covers a family of groups with a wildcard', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $other = Caches::make($persistent);
    $cache->addNonPersistentGroups('wc_cache_*');

    $cache->set('k', 'v', 'wc_cache_orders_1');

    expect($other->get('k', 'wc_cache_orders_1'))->toBeNull();
})->with('persistent harnesses');

it('expires a value after its time to live', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $cache->set('k', 'v', 'default', 10);

    $harness->advance(10);

    expect(Caches::make($persistent)->get('k'))->toBeNull();
})->with('persistent harnesses');

it('caps the time to live with the policy', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent, ttl: new TtlPolicy(maximum: 60));
    $cache->set('forever', 'v');
    $cache->add('added', 'v');
    $cache->set('negative', 'v', 'default', -5);

    $harness->advance(60);

    $fresh = Caches::make($persistent);

    expect($fresh->get('forever'))->toBeNull()
        ->and($fresh->get('added'))->toBeNull()
        ->and($fresh->get('negative'))->toBeNull();
})->with('persistent harnesses');

it('treats a negative time to live as no expiry', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $cache->set('k', 'v', 'default', -5);
    $cache->replace('k', 'w', 'default', -5);

    $harness->advance(86_400);

    expect(Caches::make($persistent)->get('k')->value)->toBe('w');
})->with('persistent harnesses');

it('passes the time to live of a replace to the store', function (StoreHarness $harness): void {
    $persistent = $harness->store();
    $cache = Caches::make($persistent);
    $cache->set('k', 'v');
    $cache->replace('k', 'w', 'default', 10);

    $harness->advance(10);

    expect(Caches::make($persistent)->get('k'))->toBeNull();
})->with('persistent harnesses');

it('uses the request cache on its own when told so', function (): void {
    $runtime = new InMemoryCacheStore;
    $cache = Caches::make(new InMemoryCacheStore, runtime: $runtime);

    $cache->set('k', 'v');

    expect($runtime->getMany(['site:default:k']))->toHaveCount(1);
});
