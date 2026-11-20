<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Contracts;

/**
 * The current time, as the stores need it to expire entries.
 *
 * Behind a contract so an expiry can be tested without waiting for it.
 */
interface Clock
{
    /**
     * The current Unix timestamp, in seconds.
     */
    public function now(): int;
}
