<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Infrastructure\WpCli\Output;

/**
 * An output that keeps what a command said, by kind.
 */
final class RecordingOutput implements Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $success = [];

    /** @var list<string> */
    public array $warning = [];

    /** @var list<string> */
    public array $error = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function success(string $message): void
    {
        $this->success[] = $message;
    }

    public function warning(string $message): void
    {
        $this->warning[] = $message;
    }

    public function error(string $message): void
    {
        $this->error[] = $message;
    }
}
