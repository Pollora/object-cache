<?php

/**
 * Plugin Name: Pollora Object Cache
 * Description: A persistent object cache for WordPress, on Redis. Installed by pollora/object-cache: do not edit, run `php artisan pollora:object-cache:enable` to update it.
 * Pollora-Object-Cache-Drop-In: 1
 *
 * This file only decides whether the package can run and hands every call over
 * to it. When it cannot (the package is not installed, or phpredis is missing)
 * it defines nothing, so WordPress finds no `wp_cache_init()` and uses its own
 * in-memory cache. Remove the file with `pollora:object-cache:disable`.
 */

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\WordPress\DropIn;

if (class_exists(DropIn::class) && DropIn::available()) {
    /**
     * Sets up the object cache global.
     */
    function wp_cache_init(): void
    {
        $GLOBALS['wp_object_cache'] = DropIn::create();
    }

    function wp_cache_add($key, $data, $group = '', $expire = 0)
    {
        return $GLOBALS['wp_object_cache']->add($key, $data, $group, $expire);
    }

    function wp_cache_add_multiple(array $data, $group = '', $expire = 0)
    {
        return $GLOBALS['wp_object_cache']->add_multiple($data, $group, $expire);
    }

    function wp_cache_replace($key, $data, $group = '', $expire = 0)
    {
        return $GLOBALS['wp_object_cache']->replace($key, $data, $group, $expire);
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        return $GLOBALS['wp_object_cache']->set($key, $data, $group, $expire);
    }

    function wp_cache_set_multiple(array $data, $group = '', $expire = 0)
    {
        return $GLOBALS['wp_object_cache']->set_multiple($data, $group, $expire);
    }

    function wp_cache_get($key, $group = '', $force = false, &$found = null)
    {
        return $GLOBALS['wp_object_cache']->get($key, $group, $force, $found);
    }

    function wp_cache_get_multiple($keys, $group = '', $force = false)
    {
        return $GLOBALS['wp_object_cache']->get_multiple($keys, $group, $force);
    }

    function wp_cache_delete($key, $group = '')
    {
        return $GLOBALS['wp_object_cache']->delete($key, $group);
    }

    function wp_cache_delete_multiple(array $keys, $group = '')
    {
        return $GLOBALS['wp_object_cache']->delete_multiple($keys, $group);
    }

    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        return $GLOBALS['wp_object_cache']->incr($key, $offset, $group);
    }

    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        return $GLOBALS['wp_object_cache']->decr($key, $offset, $group);
    }

    function wp_cache_flush()
    {
        return $GLOBALS['wp_object_cache']->flush();
    }

    function wp_cache_flush_runtime()
    {
        return $GLOBALS['wp_object_cache']->flush_runtime();
    }

    function wp_cache_flush_group($group)
    {
        return $GLOBALS['wp_object_cache']->flush_group($group);
    }

    function wp_cache_supports($feature)
    {
        return $GLOBALS['wp_object_cache']->supports($feature);
    }

    function wp_cache_close()
    {
        return true;
    }

    function wp_cache_add_global_groups($groups)
    {
        $GLOBALS['wp_object_cache']->add_global_groups($groups);
    }

    function wp_cache_add_non_persistent_groups($groups)
    {
        $GLOBALS['wp_object_cache']->add_non_persistent_groups($groups);
    }

    function wp_cache_switch_to_blog($blog_id)
    {
        $GLOBALS['wp_object_cache']->switch_to_blog($blog_id);
    }

    function wp_cache_reset()
    {
        _deprecated_function(__FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()');

        $GLOBALS['wp_object_cache']->reset();
    }
}
