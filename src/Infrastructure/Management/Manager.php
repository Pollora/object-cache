<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Management;

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;
use Pollora\ObjectCache\Infrastructure\WordPress\EngineBuilder;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use RedisException;

/**
 * What the commands do, whichever front-end runs them: `wp object-cache` in
 * WordPress, `pollora:object-cache:*` in a Pollora project.
 */
final readonly class Manager
{
    public function __construct(private DropInInstaller $installer) {}

    /**
     * A manager for the running WordPress: the drop-in goes to `object-cache.php` of the
     * content directory, and carries the path of the Composer autoloader the package is under.
     */
    public static function forWordPress(): self
    {
        $root = dirname(__DIR__, 3);
        $autoload = dirname($root, 2).'/autoload.php';
        $content = defined('WP_CONTENT_DIR') && is_string(constant('WP_CONTENT_DIR')) ? constant('WP_CONTENT_DIR') : (defined('ABSPATH') && is_string(constant('ABSPATH')) ? constant('ABSPATH').'wp-content' : 'wp-content');

        return new self(new DropInInstaller($root.'/resources/drop-in/object-cache.php', rtrim($content, '/').'/object-cache.php', is_file($autoload) ? $autoload : ''));
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
     */
    public function flush(Settings $settings): bool
    {
        return EngineBuilder::build($settings, false)->flush();
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

        return new StatusReport([
            'Drop-in' => $dropIn->value.' ('.$this->installer->target().')',
            'phpredis' => $extension ? 'loaded' : 'missing',
            'Redis' => $redis,
            'Key prefix' => $settings instanceof Settings ? EngineBuilder::prefix($settings) : '-',
            'Serializer' => $settings instanceof Settings ? $settings->serializer : '-',
            'Prefetch' => $settings instanceof Settings && $settings->prefetchEnabled ? 'on' : 'off',
            'Used by this process' => ($GLOBALS['wp_object_cache'] ?? null) instanceof WordPressObjectCache ? 'yes' : 'no',
        ], $dropIn === DropInStatus::Current && $extension && str_starts_with($redis, 'reachable'));
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

            return 'reachable'.(is_array($info) && isset($info['redis_version']) ? ' (Redis '.$info['redis_version'].')' : '');
        } catch (StoreUnavailableException|RedisException $exception) {
            return 'unreachable: '.$exception->getMessage();
        }
    }
}
