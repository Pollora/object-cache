<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\Prefetch\AccessLog;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchProfile;

it('identifies an entry by group, key and blog', function (): void {
    expect(PrefetchProfile::id('posts', 7, 1))->not->toBe(PrefetchProfile::id('posts', 7, 2))
        ->and(PrefetchProfile::id('posts', 7, 1))->not->toBe(PrefetchProfile::id('post', '7', 1).'x')
        ->and(PrefetchProfile::id('posts', 7, 1))->toBe(PrefetchProfile::id('posts', '7', 1));
});

it('parses the entries of a stored profile and drops what is not one', function (): void {
    $stored = ['e' => [['posts', 1, 1], ['options', 'alloptions', 2], ['bad'], 'nope', [1, 2, 3], ['g', 'k', '1'], ['g', 1.5, 1]]];

    expect(PrefetchProfile::parse($stored))->toBe([['posts', 1, 1], ['options', 'alloptions', 2]]);
});

it('parses nothing from a value that is not a profile', function (): void {
    expect(PrefetchProfile::parse(null))->toBe([])
        ->and(PrefetchProfile::parse('x'))->toBe([])
        ->and(PrefetchProfile::parse([]))->toBe([])
        ->and(PrefetchProfile::parse(['e' => 'x']))->toBe([]);
});

it('leaves the profile alone when the request brought nothing new', function (): void {
    expect(PrefetchProfile::merge([['a', 1, 1], ['b', 2, 1]], [['b', 2, 1]], 10))->toBeNull()
        ->and(PrefetchProfile::merge([['a', 1, 1]], [], 10))->toBeNull();
});

it('puts the keys of the request first, then the ones the profile held', function (): void {
    $merged = PrefetchProfile::merge([['a', 1, 1], ['b', 2, 1]], [['c', 3, 1], ['a', 1, 1]], 10);

    expect($merged)->toBe([['c', 3, 1], ['a', 1, 1], ['b', 2, 1]]);
});

it('cuts the profile at its limit, keeping the newest first', function (): void {
    $stored = [['a', 1, 1], ['b', 2, 1]];

    expect(PrefetchProfile::merge($stored, [['c', 3, 1]], 2))->toBe([['c', 3, 1], ['a', 1, 1]])
        ->and(PrefetchProfile::merge($stored, [['c', 3, 1]], 3))->toBe([['c', 3, 1], ['a', 1, 1], ['b', 2, 1]]);
});

it('rewrites a full profile only when a fifth of the limit is new', function (): void {
    $stored = array_map(fn (int $i): array => ['g', $i, 1], range(1, 10));
    $oneNew = [['g', 99, 1]];
    $twoNew = [['g', 98, 1], ['g', 99, 1]];

    expect(PrefetchProfile::merge($stored, $oneNew, 10))->toBeNull()
        ->and(PrefetchProfile::merge($stored, $twoNew, 10))->toHaveCount(10)
        ->and(PrefetchProfile::merge($stored, $twoNew, 10)[0])->toBe(['g', 98, 1]);
});

it('rewrites a full profile of a tiny limit as soon as one key is new', function (): void {
    expect(PrefetchProfile::merge([['g', 1, 1]], [['g', 2, 1]], 1))->toBe([['g', 2, 1]]);
});

it('records each key once, in the order it was read, up to its limit', function (): void {
    $log = new AccessLog(3);
    $log->record('posts', 1, 1);
    $log->record('posts', 1, 1);
    $log->record('posts', 2, 1);
    $log->record('posts', 1, 2);
    $log->record('posts', 4, 1);

    expect($log->entries())->toBe([['posts', 1, 1], ['posts', 2, 1], ['posts', 1, 2]]);
});
