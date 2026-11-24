<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Closure;
use Pollora\ObjectCache\Infrastructure\Config\Settings;

/**
 * Where the drop-in finds its settings.
 *
 * A framework registers a source before WordPress loads the drop-in (Pollora does,
 * from its own configuration). Without one, a classic WordPress or Bedrock site sets
 * the `WP_OBJECT_CACHE_CONFIG` constant in `wp-config.php`. Without either, the
 * package has nothing to run on and the drop-in stays inert.
 */
final class Configuration
{
    /** The constant a `wp-config.php` defines: an array, see `config/object-cache.php`. */
    public const string CONSTANT = 'WP_OBJECT_CACHE_CONFIG';

    /** @var (Closure(): array<array-key, mixed>)|null */
    private static ?Closure $source = null;

    /**
     * Register the settings of a framework, read when the drop-in builds the cache.
     *
     * @param  Closure(): array<array-key, mixed>  $source
     */
    public static function provide(Closure $source): void
    {
        self::$source = $source;
    }

    /**
     * Forget the registered source.
     */
    public static function forget(): void
    {
        self::$source = null;
    }

    /**
     * Whether a source is registered, or the constant is defined.
     */
    public static function exists(): bool
    {
        return self::$source instanceof Closure || defined(self::CONSTANT);
    }

    /**
     * The settings, or null when nothing configures the cache.
     */
    public static function settings(): ?Settings
    {
        $values = self::$source instanceof Closure ? (self::$source)() : (defined(self::CONSTANT) ? constant(self::CONSTANT) : null);

        return is_array($values) ? Settings::fromArray($values) : null;
    }
}
