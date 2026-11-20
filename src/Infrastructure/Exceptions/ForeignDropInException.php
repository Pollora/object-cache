<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Exceptions;

use RuntimeException;

/**
 * Another object cache already owns `object-cache.php`: replacing or removing it
 * would break a cache this package did not install.
 */
final class ForeignDropInException extends RuntimeException {}
