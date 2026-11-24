<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Management\Manager;
use Pollora\ObjectCache\Infrastructure\WordPress\Configuration;
use Pollora\ObjectCache\Infrastructure\WordPress\Environment;
use Pollora\ObjectCache\Infrastructure\WpCli\ObjectCacheCommand;
use Pollora\ObjectCache\Infrastructure\WpCli\WpCliOutput;

/*
 * Loaded by Composer's `autoload.files`: it declares `wp object-cache` when the
 * process is WP-CLI, and does nothing anywhere else.
 */

// @codeCoverageIgnoreStart
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('object-cache', new ObjectCacheCommand(static fn (): Manager => Manager::forWordPress(Environment::current()), new WpCliOutput, Configuration::settings(...)));
}

// @codeCoverageIgnoreEnd
