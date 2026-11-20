<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Console;

use Illuminate\Console\Command;
use Pollora\ObjectCache\Infrastructure\Exceptions\ForeignDropInException;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;

/**
 * Removes the `object-cache.php` drop-in, if it is this package's.
 */
final class DisableCommand extends Command
{
    protected $signature = 'pollora:object-cache:disable';

    protected $description = 'Remove the object-cache.php drop-in: WordPress goes back to its in-memory cache';

    public function handle(DropInInstaller $installer): int
    {
        try {
            $before = $installer->uninstall();
        } catch (ForeignDropInException $foreignDropInException) {
            $this->components->error($foreignDropInException->getMessage());

            return self::FAILURE;
        }

        if ($before === DropInStatus::Missing) {
            $this->components->info('Not installed, nothing to do.');

            return self::SUCCESS;
        }

        $this->components->info('Removed '.$installer->target().'. What Redis holds stays there: run pollora:object-cache:flush first to empty it.');

        return self::SUCCESS;
    }
}
