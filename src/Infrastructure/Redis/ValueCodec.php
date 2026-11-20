<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Redis;

use InvalidArgumentException;
use Pollora\ObjectCache\Domain\CacheValue;

/**
 * Turns a PHP value into the string Redis holds, and back.
 *
 * The format is the library's own, chosen so Redis can do arithmetic on a cached
 * number without knowing PHP's serializer, and so a value comes back with the
 * exact type it went in with:
 *
 * - an integer is its decimal text (`42`), which `INCRBY` understands;
 * - a float is `d:` and its text, a string `s:` and its bytes, null `n:`, a
 *   boolean `b:0` or `b:1`;
 * - an array or an object is `x:`, a letter for the serializer that wrote it
 *   (`p` for PHP's, `i` for igbinary) and the serialized bytes.
 *
 * A value that cannot be read back (a payload cut short, a serializer that is
 * not installed, a class that no longer exists) is a miss, never an exception.
 */
final readonly class ValueCodec
{
    public const string SERIALIZER_PHP = 'php';

    public const string SERIALIZER_IGBINARY = 'igbinary';

    /**
     * @param  string  $serializer  How arrays and objects are written: `php` or `igbinary`.
     * @param  bool|list<class-string>  $allowedClasses  The classes `unserialize()` may build: true for any, false for none, or a list.
     *
     * @throws InvalidArgumentException When the serializer is unknown, or igbinary is chosen without the extension.
     */
    public function __construct(
        private string $serializer = self::SERIALIZER_PHP,
        private bool|array $allowedClasses = true,
    ) {
        if (! in_array($serializer, [self::SERIALIZER_PHP, self::SERIALIZER_IGBINARY], true)) {
            throw new InvalidArgumentException(sprintf('Unknown object cache serializer "%s": use "php" or "igbinary".', $serializer));
        }

        // @codeCoverageIgnoreStart
        if ($serializer === self::SERIALIZER_IGBINARY && ! function_exists('igbinary_serialize')) {
            throw new InvalidArgumentException('The igbinary serializer needs the igbinary extension.');
        }

        // @codeCoverageIgnoreEnd
    }

    /**
     * The string to store for a value.
     */
    public function encode(mixed $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => 'd:'.substr(serialize($value), 2, -1),
            is_string($value) => 's:'.$value,
            $value === null => 'n:',
            is_bool($value) => $value ? 'b:1' : 'b:0',
            default => $this->serializer === self::SERIALIZER_IGBINARY
                ? 'x:i:'.igbinary_serialize($value)
                : 'x:p:'.serialize($value),
        };
    }

    /**
     * The value a stored string stands for, or null when it cannot be read.
     */
    public function decode(string $stored): ?CacheValue
    {
        if (preg_match('/^-?\d+$/', $stored) === 1) {
            return new CacheValue((int) $stored);
        }

        $payload = substr($stored, 2);

        return match (substr($stored, 0, 2)) {
            's:' => new CacheValue($payload),
            'n:' => new CacheValue(null),
            'b:' => $payload === '1' || $payload === '0' ? new CacheValue($payload === '1') : null,
            'd:' => $this->decodeFloat($payload),
            'x:' => $this->decodeObject($payload),
            default => null,
        };
    }

    private function decodeFloat(string $payload): ?CacheValue
    {
        $value = $this->unserializePhp('d:'.$payload.';');

        return is_float($value?->value) ? $value : null;
    }

    private function decodeObject(string $payload): ?CacheValue
    {
        $bytes = substr($payload, 2);

        if (str_starts_with($payload, 'p:')) {
            $value = $this->unserializePhp($bytes);
        } elseif (str_starts_with($payload, 'i:') && function_exists('igbinary_unserialize')) {
            $value = $this->unserializeIgbinary($bytes);
        } else {
            return null;
        }

        return is_array($value?->value) || is_object($value?->value) ? $value : null;
    }

    private function unserializePhp(string $bytes): ?CacheValue
    {
        set_error_handler(static fn (): bool => true);

        try {
            $value = unserialize($bytes, ['allowed_classes' => $this->allowedClasses]);
        } finally {
            restore_error_handler();
        }

        return $value === false ? null : new CacheValue($value);
    }

    private function unserializeIgbinary(string $bytes): ?CacheValue
    {
        set_error_handler(static fn (): bool => true);

        try {
            $value = igbinary_unserialize($bytes);
        } finally {
            restore_error_handler();
        }

        return $value === null ? null : new CacheValue($value);
    }
}
