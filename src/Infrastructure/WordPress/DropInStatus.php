<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

/**
 * What stands at the place of `object-cache.php`.
 */
enum DropInStatus: string
{
    /** Nothing: WordPress uses its own in-memory cache. */
    case Missing = 'missing';

    /** This package's drop-in, identical to the one the installed version ships. */
    case Current = 'current';

    /** This package's drop-in, from another version: `enable` brings it up to date. */
    case Outdated = 'outdated';

    /** Somebody else's object cache. */
    case Foreign = 'foreign';
}
