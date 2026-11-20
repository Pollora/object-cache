<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Pollora\ObjectCache\Infrastructure\Laravel\ObjectCacheServiceProvider;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

/**
 * A Laravel application with the package's service provider, pointed at the
 * Redis of the tests and at a drop-in folder of its own.
 */
abstract class FeatureTestCase extends TestCase
{
    protected string $contentDir = '';

    protected function getPackageProviders($app): array
    {
        return [ObjectCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->contentDir = sys_get_temp_dir().'/object-cache-content-'.bin2hex(random_bytes(4));
        mkdir($this->contentDir);

        $app['config']->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 0,
        ]);
        $app['config']->set('pollora.object-cache.redis.database', RedisHarness::database());
        $app['config']->set('pollora.object-cache.prefix', 'feature-test');

        $app->singleton(DropInInstaller::class, fn (): DropInInstaller => new DropInInstaller(
            dirname(__DIR__, 2).'/resources/drop-in/object-cache.php',
            $this->contentDir.'/object-cache.php',
        ));
    }

    protected function tearDown(): void
    {
        // Never glob an empty path: '/*' would be the root of the disk.
        if ($this->contentDir !== '' && is_dir($this->contentDir)) {
            foreach (glob($this->contentDir.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->contentDir);
        }

        parent::tearDown();
    }
}
