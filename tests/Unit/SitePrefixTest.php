<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Pollora\ObjectCache\Infrastructure\Laravel\SitePrefix;

function prefixConfig(array $overrides = []): Repository
{
    return new Repository(array_replace_recursive([
        'app' => ['url' => 'https://example.test'],
        'database' => ['default' => 'wordpress', 'connections' => ['wordpress' => ['database' => 'wp', 'prefix' => 'wp_']]],
    ], $overrides));
}

it('uses the configured prefix first', function (): void {
    $config = prefixConfig(['pollora' => ['object-cache' => ['prefix' => 'mine']]]);

    expect(SitePrefix::resolve($config, 'salt'))->toBe('mine');
});

it('uses WP_CACHE_KEY_SALT when no prefix is configured', function (): void {
    expect(SitePrefix::resolve(prefixConfig(), 'salt'))->toBe('salt');
});

it('ignores a blank configured prefix or salt', function (): void {
    $config = prefixConfig(['pollora' => ['object-cache' => ['prefix' => '  ']]]);

    expect(SitePrefix::resolve($config, ''))->toStartWith('pollora-')
        ->and(SitePrefix::resolve($config, null))->toStartWith('pollora-');
});

it('derives a stable prefix from the site URL, the database and the table prefix', function (): void {
    $prefix = SitePrefix::resolve(prefixConfig());

    expect($prefix)->toMatch('/^pollora-[0-9a-f]{12}$/')
        ->and(SitePrefix::resolve(prefixConfig()))->toBe($prefix);
});

it('derives another prefix for another site, database or table prefix', function (array $change): void {
    expect(SitePrefix::resolve(prefixConfig($change)))->not->toBe(SitePrefix::resolve(prefixConfig()));
})->with([
    'another URL' => [['app' => ['url' => 'https://other.test']]],
    'another database' => [['database' => ['connections' => ['wordpress' => ['database' => 'other']]]]],
    'another table prefix' => [['database' => ['connections' => ['wordpress' => ['prefix' => 'x_']]]]],
]);

it('derives a prefix when the database is not configured', function (): void {
    expect(SitePrefix::resolve(new Repository([])))->toMatch('/^pollora-[0-9a-f]{12}$/');
});

it('ignores a salt made of blanks', function (): void {
    expect(SitePrefix::resolve(prefixConfig(), "  \t"))->toStartWith('pollora-');
});

it('derives the prefix from the first twelve characters of a hash of the three values', function (): void {
    expect(SitePrefix::resolve(prefixConfig()))->toBe('pollora-'.substr(sha1('https://example.test|wp|wp_'), 0, 12))
        ->and(SitePrefix::resolve(new Repository([])))->toBe('pollora-'.substr(sha1('||'), 0, 12))
        ->and(SitePrefix::resolve(new Repository(['app' => ['url' => 'https://a.test']])))->toBe('pollora-'.substr(sha1('https://a.test||'), 0, 12))
        ->and(SitePrefix::resolve(new Repository(['database' => ['default' => 'x', 'connections' => ['x' => ['database' => 'd']]]])))->toBe('pollora-'.substr(sha1('|d|'), 0, 12))
        ->and(SitePrefix::resolve(new Repository(['database' => ['default' => 'x', 'connections' => ['x' => ['prefix' => 'p_']]]])))->toBe('pollora-'.substr(sha1('||p_'), 0, 12));
});
