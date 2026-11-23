<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Exceptions;

use RuntimeException;

/**
 * A store could not be reached: Redis is down, the connection timed out or the
 * extension is missing.
 *
 * Not an error for the site. The object cache catches it, serves the request
 * from its request cache and reports itself degraded.
 */
final class StoreUnavailableException extends RuntimeException {}
