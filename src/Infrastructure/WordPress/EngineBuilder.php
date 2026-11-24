<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Infrastructure\Config\CacheFactory;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Config\SitePrefix;

/**
 * Builds the object cache of a WordPress: the settings, plus what the environment says
 * (the salt, the database, the blog, the page).
 */
final class EngineBuilder
{
    /**
     * @param  bool  $request  Whether a web request is being served, so the prefetch may take part; false for a console command.
     */
    public static function build(Settings $settings, Environment $environment, bool $request = true): ObjectCache
    {
        return CacheFactory::make(
            $settings,
            self::prefix($settings, $environment),
            $environment->multisite,
            $environment->blogId,
            $request ? RequestSignature::resolve($environment->sapi, $environment->server, $environment->cookies, $environment->dynamic, $environment->blogId) : null,
        );
    }

    /**
     * The key prefix of this site.
     */
    public static function prefix(Settings $settings, Environment $environment): string
    {
        return SitePrefix::resolve($settings->prefix, $environment->salt, $environment->database, $environment->host, $environment->tablePrefix);
    }
}
