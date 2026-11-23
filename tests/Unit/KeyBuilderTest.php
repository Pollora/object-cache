<?php

declare(strict_types=1);

use Pollora\ObjectCache\Domain\KeyBuilder;

it('builds a key from the site, the group and the key', function (): void {
    $keys = new KeyBuilder('site');

    expect($keys->key('posts', 12, false, 1))->toBe('site:posts:12')
        ->and($keys->key('posts', 'abc', true, 1))->toBe('site:posts:abc');
});

it('adds the blog to the key of a blog-specific group in a multisite only', function (): void {
    $multisite = new KeyBuilder('site', multisite: true);
    $single = new KeyBuilder('site');

    expect($multisite->key('posts', 12, false, 3))->toBe('site:posts:3:12')
        ->and($multisite->key('users', 12, true, 3))->toBe('site:users:12')
        ->and($single->key('posts', 12, false, 3))->toBe('site:posts:12');
});

it('encodes separators so a group or a key cannot pass for another', function (): void {
    $keys = new KeyBuilder('site', multisite: true);

    expect($keys->key('a:b', 'c', false, 1))->not->toBe($keys->key('a', 'b:c', false, 1))
        ->and($keys->key('posts', '2:x', false, 1))->not->toBe($keys->key('posts', 'x', false, 2))
        ->and($keys->key('my group', 'a b/c', true, 1))->toBe('site:my%20group:a%20b%2Fc');
});

it('scopes a flush to the site, and to a group', function (): void {
    $keys = new KeyBuilder('my site');

    expect($keys->siteScope())->toBe('my%20site:')
        ->and($keys->groupScope('posts'))->toBe('my%20site:posts:')
        ->and($keys->groupScope(''))->toBe('my%20site:default:');
});

it('does not let one site scope cover another', function (): void {
    $short = new KeyBuilder('a');
    $long = new KeyBuilder('a:b');

    expect(str_starts_with($long->key('g', 'k', false, 1), $short->siteScope()))->toBeFalse();
});

it('refuses an empty site prefix', function (string $prefix): void {
    expect(fn () => new KeyBuilder($prefix))->toThrow(InvalidArgumentException::class);
})->with(['empty' => [''], 'blank' => ['  ']]);

it('names the default group when none is given', function (): void {
    expect(KeyBuilder::normalizeGroup(''))->toBe('default')
        ->and(KeyBuilder::normalizeGroup('posts'))->toBe('posts');
});

it('tells valid keys from invalid ones', function (mixed $key, bool $valid): void {
    expect(KeyBuilder::isValidKey($key))->toBe($valid);
})->with([
    'int' => [7, true],
    'zero' => [0, true],
    'string' => ['k', true],
    'string with spaces around' => [' k ', true],
    'empty' => ['', false],
    'blank' => [" \t", false],
    'null' => [null, false],
    'float' => [1.5, false],
    'bool' => [true, false],
    'array' => [['k'], false],
]);
