<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Clock;

use Pollora\ObjectCache\Domain\Contracts\Clock;

/**
 * The real clock: the time of the server.
 */
final class SystemClock implements Clock
{
    /**
     * The current Unix timestamp, in seconds.
     */
    public function now(): int
    {
        return time();
    }
}
