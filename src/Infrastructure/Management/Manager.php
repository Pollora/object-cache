<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Management;

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;
use Pollora\ObjectCache\Infrastructure\WordPress\EngineBuilder;
use Pollora\ObjectCache\Infrastructure\WordPress\Environment;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use RedisException;

/**
 * What the commands do, whichever front-end runs them: `wp object-cache` in
 * WordPress, `pollora:object-cache:*` in a Pollora project.
 */
final readonly class Manager
{
    public function __construct(private DropInInstaller $installer, private Environment $environment) {}

    /**
     * A manager for a WordPress: the drop-in goes to `object-cache.php` of its content directory,
     * and carries the path of the Composer autoloader the package is installed under, when there is one.
     *
     * @param  string|null  $packageRoot  Where the package is; its own directory by default.
     */
    public static function forWordPress(Environment $environment, ?string $packageRoot = null): self
    {
        $root = $packageRoot ?? dirname(__DIR__, 3);
        $autoload = dirname($root, 2).'/autoload.php';

        return new self(new DropInInstaller($root.'/resources/drop-in/object-cache.php', rtrim($environment->contentDir ?? 'wp-content', '/').'/object-cache.php', is_file($autoload) ? $autoload : ''), $environment);
    }

    /**
     * Put the drop-in in place.
     *
     * @return DropInStatus What stood at the target before.
     */
    public function enable(bool $force = false): DropInStatus
    {
        return $this->installer->install($force);
    }

    /**
     * Take the drop-in out, if it is this package's.
     *
     * @return DropInStatus What stood at the target before.
     */
    public function disable(): DropInStatus
    {
        return $this->installer->uninstall();
    }

    /**
     * Empty this site's keys, and nothing else.
     *
     * @return bool False when Redis could not be reached, so nothing was emptied.
     */
    public function flush(Settings $settings): bool
    {
        $cache = EngineBuilder::build($settings, $this->environment, false);
        $cache->flush();

        return ! $cache->isDegraded();
    }

    /**
     * Where things stand.
     *
     * @param  Settings|null  $settings  The settings, or null when nothing configures the cache.
     */
    public function status(?Settings $settings): StatusReport
    {
        $dropIn = $this->installer->status();
        $extension = extension_loaded('redis');
        $redis = $settings instanceof Settings ? $this->redis($settings, $extension) : 'not configured: define WP_OBJECT_CACHE_CONFIG';
        $autoload = $this->installer->autoload();
        $reachable = $autoload === '' || is_readable($autoload);

        return new StatusReport([
            'Drop-in' => $dropIn->value.' ('.$this->installer->target().')',
            'phpredis' => $extension ? 'loaded' : 'missing',
            'Autoloader' => $this->autoloadLine($autoload, $reachable),
            'Redis' => $redis,
            'Key prefix' => $settings instanceof Settings ? EngineBuilder::prefix($settings, $this->environment) : '-',
            'Serializer' => $settings instanceof Settings ? $settings->serializer : '-',
            'Prefetch' => $settings instanceof Settings && $settings->prefetchEnabled ? 'on' : 'off',
            'Used by this process' => ($GLOBALS['wp_object_cache'] ?? null) instanceof WordPressObjectCache ? 'yes' : 'no',
        ], $dropIn === DropInStatus::Current && $extension && $reachable && str_starts_with($redis, 'reachable'));
    }

    /**
     * What `status` says of the autoloader the drop-in loads.
     *
     * Only this user can be asked whether the path is readable. A path under the home directory of
     * the user who runs the command (where `wp package install` puts a package) is flagged,
     * because the web server usually cannot read it, and the cache then stays off without an error.
     */
    private function autoloadLine(string $autoload, bool $reachable): string
    {
        if ($autoload === '') {
            return 'none (WordPress must load the package itself)';
        }

        if (! $reachable) {
            return $autoload.' (not readable by this user)';
        }

        $home = $this->environment->server['HOME'] ?? null;

        if (is_string($home) && $home !== '' && str_starts_with($autoload, rtrim($home, '/').'/')) {
            return $autoload.' (in the home directory: the web server must be able to read it, or the cache stays off without an error)';
        }

        return $autoload;
    }

    private function redis(Settings $settings, bool $extension): string
    {
        // @codeCoverageIgnoreStart
        if (! $extension) {
            return 'not checked';
        }

        // @codeCoverageIgnoreEnd

        try {
            $info = (new PhpRedisConnector($settings->redis))->connect()->info('server');
            $version = is_array($info) ? ($info['redis_version'] ?? null) : null;

            return 'reachable'.(is_scalar($version) ? ' (Redis '.$version.')' : '');
        } catch (StoreUnavailableException|RedisException $exception) {
            return 'unreachable: '.$exception->getMessage();
        }
    }
}
