<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Exceptions;

use RuntimeException;

/**
 * The `object-cache.php` drop-in could not be written or removed.
 */
final class DropInFileException extends RuntimeException
{
    /**
     * The drop-in could not be copied to the content directory.
     */
    public static function cannotWrite(string $target): self
    {
        return new self(sprintf('Cannot write %s. Check that the content directory exists and that the web user can write to it.', $target));
    }

    /**
     * The drop-in could not be deleted from the content directory.
     */
    public static function cannotRemove(string $target): self
    {
        return new self(sprintf('Cannot remove %s. Check that the web user can delete files in the content directory.', $target));
    }
}
