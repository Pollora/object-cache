<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Pollora\ObjectCache\Infrastructure\Exceptions\ForeignDropInException;
use Pollora\ObjectCache\Infrastructure\Laravel\ConnectorFactory;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;

/**
 * Installs the `object-cache.php` drop-in, or brings it up to date.
 */
final class EnableCommand extends Command
{
    protected $signature = 'pollora:object-cache:enable {--force : Replace an object-cache.php that belongs to another object cache}';

    protected $description = 'Install the object-cache.php drop-in that turns the persistent object cache on';

    public function handle(DropInInstaller $installer, Repository $config): int
    {
        try {
            $before = $installer->install((bool) $this->option('force'));
        } catch (ForeignDropInException $foreignDropInException) {
            $this->components->error($foreignDropInException->getMessage());

            return self::FAILURE;
        }

        match ($before) {
            DropInStatus::Missing => $this->components->info('Installed '.$installer->target().'.'),
            DropInStatus::Outdated => $this->components->info('Updated '.$installer->target().'.'),
            DropInStatus::Current => $this->components->info('Already installed, nothing to do.'),
            DropInStatus::Foreign => $this->components->warn('Replaced another object cache at '.$installer->target().'.'),
        };

        // @codeCoverageIgnoreStart
        if (! extension_loaded('redis')) {
            $this->components->warn('The redis PHP extension is not loaded: WordPress keeps using its own in-memory cache until it is.');

            return self::SUCCESS;
        }

        // @codeCoverageIgnoreEnd

        try {
            ConnectorFactory::make($config)->connect();
        } catch (StoreUnavailableException $storeUnavailableException) {
            $this->components->warn('Redis does not answer ('.$storeUnavailableException->getMessage().'): the site runs on its in-memory cache until it does.');
        }

        return self::SUCCESS;
    }
}
