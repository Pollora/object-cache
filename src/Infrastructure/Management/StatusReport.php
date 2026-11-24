<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Management;

/**
 * What `status` says: one line per fact, and whether the cache can serve.
 */
final readonly class StatusReport
{
    /**
     * @param  array<string, string>  $lines  A label and its value, in the order they are shown.
     * @param  bool  $healthy  The drop-in is current, phpredis is loaded and Redis answers.
     */
    public function __construct(public array $lines, public bool $healthy) {}
}
