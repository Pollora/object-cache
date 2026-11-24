<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WpCli;

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Exceptions\DropInFileException;
use Pollora\ObjectCache\Infrastructure\Exceptions\ForeignDropInException;
use Pollora\ObjectCache\Infrastructure\Management\Manager;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;

/**
 * Manage the persistent object cache.
 *
 * Settings come from the `WP_OBJECT_CACHE_CONFIG` constant of `wp-config.php`.
 */
final readonly class ObjectCacheCommand
{
    /**
     * @param  \Closure(): Manager  $manager  Builds the manager when a command runs: `wp-config.php` has been read by then, and defines the content directory.
     * @param  \Closure(): ?Settings  $settings  The settings, or null when nothing configures the cache.
     */
    public function __construct(private \Closure $manager, private Output $output, private \Closure $settings) {}

    /**
     * Install the object-cache.php drop-in.
     *
     * Another object cache's drop-in is left alone unless `--force` replaces it.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Replace an object-cache.php that belongs to another object cache.
     *
     * ## EXAMPLES
     *
     *     wp object-cache enable
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function enable(array $args = [], array $assoc = []): void
    {
        try {
            $before = ($this->manager)()->enable(($assoc['force'] ?? false) === true);
        } catch (ForeignDropInException|DropInFileException $exception) {
            $this->output->error($exception->getMessage());

            return;
        }

        $this->output->success(match ($before) {
            DropInStatus::Current => 'Already installed.',
            DropInStatus::Outdated => 'Updated the drop-in.',
            DropInStatus::Foreign => 'Replaced the other object cache.',
            DropInStatus::Missing => 'Installed the drop-in.',
        });

        if (! ($this->settings)() instanceof Settings) {
            $this->output->warning('Nothing configures the cache yet: define WP_OBJECT_CACHE_CONFIG in wp-config.php, or WordPress keeps its own cache.');
        }
    }

    /**
     * Remove the object-cache.php drop-in.
     *
     * What Redis holds stays there; run `wp object-cache flush` first to empty it.
     *
     * ## EXAMPLES
     *
     *     wp object-cache disable
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function disable(array $args = [], array $assoc = []): void
    {
        try {
            $before = ($this->manager)()->disable();
        } catch (ForeignDropInException|DropInFileException $exception) {
            $this->output->error($exception->getMessage());

            return;
        }

        $this->output->success($before === DropInStatus::Missing ? 'Nothing to remove.' : 'Removed the drop-in.');
    }

    /**
     * Show whether the object cache is installed and reachable.
     *
     * ## EXAMPLES
     *
     *     wp object-cache status
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $report = ($this->manager)()->status(($this->settings)());

        foreach ($report->lines as $label => $value) {
            $this->output->line(sprintf('%-22s %s', $label.':', $value));
        }

        if (! $report->healthy) {
            $this->output->error('The object cache is not working.');
        }
    }

    /**
     * Empty the keys of this site.
     *
     * Only the keys under this site's prefix go: the rest of a shared Redis stays.
     *
     * ## EXAMPLES
     *
     *     wp object-cache flush
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function flush(array $args = [], array $assoc = []): void
    {
        $settings = ($this->settings)();

        if (! $settings instanceof Settings) {
            $this->output->error('Nothing configures the cache: define WP_OBJECT_CACHE_CONFIG in wp-config.php.');

            return;
        }

        if (! ($this->manager)()->flush($settings)) {
            $this->output->error('Redis could not be reached, nothing was flushed.');

            return;
        }

        $this->output->success('Emptied the keys of this site.');
    }
}
