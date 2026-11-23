<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Infrastructure\Redis\RedisConnector;
use Redis;

/**
 * Counts how many times a store opens a connection, and hands out the client it
 * was given, or a fresh one from another connector.
 */
final class CountingConnector implements RedisConnector
{
    public int $connections = 0;

    public function __construct(private readonly RedisConnector $inner) {}

    public function connect(): Redis
    {
        $this->connections++;

        return $this->inner->connect();
    }
}
