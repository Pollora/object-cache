<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Prefetch;

use __PHP_Incomplete_Class;
use SplObjectStorage;

/**
 * Finds the objects PHP could not rebuild because their class was not loaded yet.
 *
 * The prefetch reads values at the first read of a request, long before WordPress
 * and the plugins have loaded all their classes. `unserialize()` then returns a
 * `__PHP_Incomplete_Class` instead of the object, and code that uses it later fails.
 * A value that holds one is left out of the prefetch and read, when asked for, at a
 * point where the class is known.
 */
final class IncompleteObjects
{
    /**
     * Whether the value is, or contains at any depth, an object whose class is missing.
     */
    public static function in(mixed $value): bool
    {
        return self::search($value, new SplObjectStorage);
    }

    /**
     * @param  SplObjectStorage<object, mixed>  $seen  Objects already visited, so a cycle ends.
     */
    private static function search(mixed $value, SplObjectStorage $seen): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::search($item, $seen)) {
                    return true;
                }
            }

            return false;
        }

        // Checked first: is_object() answers false for an incomplete class.
        if ($value instanceof __PHP_Incomplete_Class) {
            return true;
        }

        if (! is_object($value)) {
            return false;
        }

        if ($seen->offsetExists($value)) {
            return false;
        }

        $seen->offsetSet($value);

        return self::search((array) $value, $seen);
    }
}
