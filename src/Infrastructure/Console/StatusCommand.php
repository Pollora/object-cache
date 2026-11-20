<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Laravel\ConnectorFactory;
use Pollora\ObjectCache\Infrastructure\Laravel\SitePrefix;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;
use RedisException;

/**
 * Says whether the object cache is installed and working, and with which settings.
 */
final class StatusCommand extends Command
{
    protected $signature = 'pollora:object-cache:status';

    protected $description = 'Show whether the persistent object cache is installed and reachable';

    public function handle(DropInInstaller $installer, Repository $config): int
    {
        $dropIn = $installer->status();
        $extension = extension_loaded('redis');
        $redis = $this->redisState($config, $extension);
        $healthy = $dropIn === DropInStatus::Current && $extension && str_starts_with($redis, 'reachable');

        $this->components->twoColumnDetail('Drop-in', $dropIn->value.' ('.$installer->target().')');
        $this->components->twoColumnDetail('phpredis', $extension ? 'loaded' : 'missing');
        $this->components->twoColumnDetail('Redis', $redis);
        $this->components->twoColumnDetail('Connection', (string) $config->get('pollora.object-cache.connection', 'default'));
        $this->components->twoColumnDetail('Key prefix', SitePrefix::resolve($config, defined('WP_CACHE_KEY_SALT') ? (string) constant('WP_CACHE_KEY_SALT') : null));
        $this->components->twoColumnDetail('Serializer', (string) $config->get('pollora.object-cache.serializer', 'php'));
        $this->components->twoColumnDetail('Used by this process', ($GLOBALS['wp_object_cache'] ?? null) instanceof WordPressObjectCache ? 'yes' : 'no');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function redisState(Repository $config, bool $extension): string
    {
        // @codeCoverageIgnoreStart
        if (! $extension) {
            return 'not checked';
        }

        // @codeCoverageIgnoreEnd

        try {
            $redis = ConnectorFactory::make($config)->connect();
            $info = $redis->info('server');

            return 'reachable'.(is_array($info) && isset($info['redis_version']) ? ' (Redis '.$info['redis_version'].')' : '');
        } catch (StoreUnavailableException|RedisException $exception) {
            return 'unreachable: '.$exception->getMessage();
        }
    }
}
