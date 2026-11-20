<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use Pollora\ObjectCache\Tests\Support\Caches;

/*
 * Loads the drop-in in a PHP process of its own, the way WordPress does, and
 * prints what it found as JSON. Run with `php -n` for a PHP without extensions.
 *
 * usage: php dropin-runner.php <project root> <drop-in file> [use]
 */

[, $root, $dropIn, $mode] = $argv + [null, null, null, 'load'];

require $root.'/vendor/autoload.php';

if ($mode === 'use') {
    $container = new Container;
    Container::setInstance($container);
    $container->bind(
        WordPressObjectCache::class,
        static fn (): WordPressObjectCache => new WordPressObjectCache(
            Caches::make(new InMemoryCacheStore),
        ),
    );
}

require $dropIn;

$result = ['init' => function_exists('wp_cache_init')];

if ($result['init']) {
    wp_cache_init();
    $found = null;
    wp_cache_set('k', ['v'], 'g');
    $result['value'] = wp_cache_get('k', 'g', false, $found);
    $result['found'] = $found;
    $result['missing'] = wp_cache_get('nope', 'g', false, $found);
    $result['missing_found'] = $found;
    wp_cache_set('n', 1, 'g');
    $result['incr'] = wp_cache_incr('n', 2, 'g');
    $result['flush'] = wp_cache_flush();
    $result['after_flush'] = wp_cache_get('k', 'g');
    $result['supports'] = wp_cache_supports('flush_group');
    $result['close'] = wp_cache_close();
    $result['functions'] = array_values(array_filter(
        ['wp_cache_add', 'wp_cache_add_multiple', 'wp_cache_replace', 'wp_cache_set', 'wp_cache_set_multiple', 'wp_cache_get', 'wp_cache_get_multiple', 'wp_cache_delete', 'wp_cache_delete_multiple', 'wp_cache_incr', 'wp_cache_decr', 'wp_cache_flush', 'wp_cache_flush_runtime', 'wp_cache_flush_group', 'wp_cache_supports', 'wp_cache_close', 'wp_cache_add_global_groups', 'wp_cache_add_non_persistent_groups', 'wp_cache_switch_to_blog', 'wp_cache_reset'],
        static fn (string $name): bool => ! function_exists($name),
    ));
}

echo json_encode($result, JSON_THROW_ON_ERROR);
