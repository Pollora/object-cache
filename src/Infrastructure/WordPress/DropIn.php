<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Config\Settings;

/**
 * What the `object-cache.php` drop-in asks of the package.
 *
 * The drop-in stays a thin file: it decides, with `available()`, whether to
 * define the `wp_cache_*` functions at all, and builds the cache with `create()`.
 * When `available()` is false the drop-in defines nothing, WordPress finds no
 * `wp_cache_init()` and runs on its own in-memory cache, with no error.
 *
 * Whether Redis answers is not decided here: a Redis that is down is an event of
 * the request, and `ObjectCache` degrades by itself.
 */
final class DropIn
{
    private static bool $reporting = false;

    /**
     * Whether the package can run: phpredis is loaded and something configures the cache
     * (a framework's source, or the `WP_OBJECT_CACHE_CONFIG` constant).
     */
    public static function available(): bool
    {
        return extension_loaded('redis') && Configuration::exists();
    }

    /**
     * A fresh object cache for `$wp_object_cache`.
     *
     * The first failure of Redis during the request is written to the PHP error
     * log when the request ends, once.
     */
    public static function create(): WordPressObjectCache
    {
        $settings = Configuration::settings() ?? Settings::fromArray([]);
        $cache = new WordPressObjectCache(EngineBuilder::build($settings, Environment::current()));

        if (! self::$reporting) {
            self::$reporting = true;
            register_shutdown_function(self::report(...), $cache);
        }

        return $cache;
    }

    /**
     * Save what the request read for the prefetch, then write the failure of Redis, if there was one, to the PHP error log.
     *
     * @param  int|false|null  $status  The HTTP status of the response; read from PHP when null.
     */
    public static function report(WordPressObjectCache $cache, int|false|null $status = null): void
    {
        $cache->engine()->savePrefetch(($status ?? http_response_code()) === 200);

        $failure = $cache->engine()->failure();

        if ($failure instanceof StoreUnavailableException) {
            error_log('[pollora/object-cache] Redis unavailable, serving from the request cache: '.$failure->getMessage());
        }
    }
}
