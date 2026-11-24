<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WpCli;

/**
 * What a command says to the person who ran it: WP-CLI's output in WordPress, a fake in tests.
 */
interface Output
{
    /**
     * A plain line.
     */
    public function line(string $message): void;

    /**
     * Something went well.
     */
    public function success(string $message): void;

    /**
     * Something is worth a look, the command goes on.
     */
    public function warning(string $message): void;

    /**
     * The command stops, with a failure.
     */
    public function error(string $message): void;
}
