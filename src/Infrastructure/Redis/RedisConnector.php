<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Redis;

use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;
use Redis;

/**
 * Opens the connection a Redis store talks through.
 *
 * The store asks for it on its first command, never earlier, so a site whose
 * Redis is down only pays for the attempt when the cache is actually used.
 */
interface RedisConnector
{
    /**
     * A client ready to use: connected, authenticated, on the right database,
     * and with no serializer, compression or key prefix of its own, because the
     * store builds its keys and encodes its values itself.
     *
     * @throws StoreUnavailableException When Redis cannot be reached or refuses the credentials.
     */
    public function connect(): Redis;
}
