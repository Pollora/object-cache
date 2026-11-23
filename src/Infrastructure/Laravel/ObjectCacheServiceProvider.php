<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Infrastructure\Console\DisableCommand;
use Pollora\ObjectCache\Infrastructure\Console\EnableCommand;
use Pollora\ObjectCache\Infrastructure\Console\FlushCommand;
use Pollora\ObjectCache\Infrastructure\Console\StatusCommand;
use Pollora\ObjectCache\Infrastructure\Laravel\Cache\ObjectCacheStore;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;

/**
 * Wires the object cache into a Pollora project.
 *
 * The bindings live in `register()`, not `boot()`: WordPress loads the
 * `object-cache.php` drop-in while Laravel is registered but not yet booted, and
 * the drop-in asks the container for the cache.
 */
final class ObjectCacheServiceProvider extends ServiceProvider
{
    /** The event a queue worker fires before each job; named as a string so the queue package is not required. */
    private const string JOB_PROCESSING = JobProcessing::class;

    /** The cache driver name to put in `config/cache.php` (`'driver' => 'wordpress'`). */
    private const string DRIVER = 'wordpress';

    /** What `php artisan cache:clear` dispatches once it has emptied a store. */
    private const string CACHE_CLEARED = 'cache:cleared';

    /**
     * Merge the configuration and bind the cache, its WordPress facade and the
     * drop-in installer.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/object-cache.php', 'pollora.object-cache');

        $this->app->bind(ObjectCache::class, static function (Application $app): ObjectCache {
            $salt = defined('WP_CACHE_KEY_SALT') ? constant('WP_CACHE_KEY_SALT') : null;

            return ObjectCacheFactory::make(
                $app->make('config'),
                multisite: function_exists('is_multisite') && is_multisite(),
                blogId: function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
                salt: is_string($salt) ? $salt : null,
            );
        });

        $this->app->bind(WordPressObjectCache::class, static fn (Application $app): WordPressObjectCache => new WordPressObjectCache($app->make(ObjectCache::class)));

        $this->app->singleton(DropInInstaller::class, static fn (Application $app): DropInInstaller => new DropInInstaller(
            __DIR__.'/../../../resources/drop-in/object-cache.php',
            (defined('WP_CONTENT_DIR') ? (string) constant('WP_CONTENT_DIR') : $app->basePath('public/content')).'/object-cache.php',
        ));
    }

    /**
     * Register the commands and the `wordpress` cache driver, empty the object
     * cache along with `cache:clear`, and empty the request cache before each
     * queued job.
     *
     * A worker lives for hours: without this, every job would start with the
     * values the previous one cached, and a Redis outage would last until the
     * worker restarts.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnableCommand::class, DisableCommand::class, StatusCommand::class, FlushCommand::class]);

            $this->publishes([__DIR__.'/../../../config/object-cache.php' => $this->app->configPath('object-cache.php')], 'object-cache-config');
        }

        $this->registerCacheDriver();

        $events = $this->app->make('events');

        $events->listen(self::JOB_PROCESSING, static function (): void {
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        });

        $events->listen(self::CACHE_CLEARED, $this->flushWithCacheClear(...));
    }

    /**
     * Make `Cache::store('wordpress')` available to the project's cache stores.
     */
    private function registerCacheDriver(): void
    {
        $this->callAfterResolving('cache', function (CacheManager $cache): void {
            $cache->extend(self::DRIVER, function (Application $app, array $config) use ($cache) {
                $group = $app->make('config')->get('pollora.object-cache.laravel.group', 'laravel');

                return $cache->repository(new ObjectCacheStore($app->make(ObjectCache::class), is_string($group) && $group !== '' ? $group : 'laravel'), $config);
            });
        });
    }

    /**
     * Empty the site's object cache when `cache:clear` empties another store.
     *
     * A `wordpress` store already took its own group away, so nothing more goes
     * for it. Only this site's keys are removed, never the Redis database, and
     * an unreachable Redis does not fail the command: the cache degrades.
     *
     * @param  string|null  $store  The store the command was asked to clear; null for the default one.
     */
    private function flushWithCacheClear(?string $store = null): void
    {
        $config = $this->app->make('config');

        if (! (bool) $config->get('pollora.object-cache.laravel.flush_with_cache_clear', true)) {
            return;
        }

        $name = $store ?? $config->get('cache.default');

        if ($config->get('cache.stores.'.(is_string($name) ? $name : '').'.driver') === self::DRIVER) {
            return;
        }

        $this->app->make(ObjectCache::class)->flush();

        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }
}
