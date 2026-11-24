<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Infrastructure\Config\CacheFactory;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Config\SitePrefix;

/**
 * Builds the object cache of the running WordPress: the settings, plus what
 * `wp-config.php` and the request say (the salt, the database, the blog, the page).
 */
final class EngineBuilder
{
    /**
     * @param  bool  $request  Whether a web request is being served, so the prefetch may take part; false for a console command.
     */
    public static function build(Settings $settings, bool $request = true): ObjectCache
    {
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

        return CacheFactory::make(
            $settings,
            self::prefix($settings),
            multisite: function_exists('is_multisite') && is_multisite(),
            blogId: $blogId,
            requestKind: $request ? RequestSignature::resolve(
                PHP_SAPI,
                $_SERVER,
                $_COOKIE,
                defined('WP_ADMIN') || defined('DOING_AJAX') || defined('DOING_CRON') || defined('REST_REQUEST') || defined('XMLRPC_REQUEST'),
                $blogId,
            ) : null,
        );
    }

    /**
     * The key prefix of this site.
     */
    public static function prefix(Settings $settings): string
    {
        $table = $GLOBALS['table_prefix'] ?? '';

        return SitePrefix::resolve(
            $settings->prefix,
            self::constant('WP_CACHE_KEY_SALT'),
            self::constant('DB_NAME') ?? '',
            self::constant('DB_HOST') ?? '',
            is_string($table) ? $table : '',
        );
    }

    private static function constant(string $name): ?string
    {
        return defined($name) && is_string(constant($name)) ? constant($name) : null;
    }
}
