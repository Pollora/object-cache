<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Config;

/**
 * The prefix that tells one site's keys from another's on a shared Redis, and
 * scopes a flush to one site.
 *
 * It must not need the database: the drop-in builds the cache before WordPress
 * can read an option, and asking for one there would loop back into the cache.
 * Without a prefix in the settings, it comes from what `wp-config.php` defines.
 */
final class SitePrefix
{
    /**
     * @param  string|null  $configured  The `prefix` setting.
     * @param  string|null  $salt  `WP_CACHE_KEY_SALT`, when the project defines it.
     */
    public static function resolve(?string $configured, ?string $salt, string $database, string $host, string $tablePrefix): string
    {
        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }

        if ($salt !== null && trim($salt) !== '') {
            return $salt;
        }

        return 'wp-'.substr(sha1(implode('|', [$database, $host, $tablePrefix])), 0, 12);
    }
}
