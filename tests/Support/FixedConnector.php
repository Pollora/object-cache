<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Infrastructure\Redis\RedisConnector;
use Redis;

/**
 * Hands out one client, as it is.
 */
final readonly class FixedConnector implements RedisConnector
{
    public function __construct(private Redis $client) {}

    public function connect(): Redis
    {
        return $this->client;
    }
}
