<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WpCli;

use WP_CLI;

/**
 * The output of WP-CLI, which exits on an error.
 *
 * @codeCoverageIgnore It only forwards to the `WP_CLI` class, which exists inside WP-CLI.
 */
final class WpCliOutput implements Output
{
    public function line(string $message): void
    {
        WP_CLI::line($message);
    }

    public function success(string $message): void
    {
        WP_CLI::success($message);
    }

    public function warning(string $message): void
    {
        WP_CLI::warning($message);
    }

    public function error(string $message): void
    {
        WP_CLI::error($message);
    }
}
