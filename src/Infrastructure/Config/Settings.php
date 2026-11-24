<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Config;

use Pollora\ObjectCache\Infrastructure\Redis\ValueCodec;

/**
 * What the object cache is configured with, read from a plain array.
 *
 * The array is the `WP_OBJECT_CACHE_CONFIG` constant of a `wp-config.php`, or what a
 * framework gives the package (Pollora builds it from its own configuration). Every
 * key is optional; `config/object-cache.php` lists them with their defaults.
 */
final readonly class Settings
{
    /**
     * @param  array{host?: string, port?: int, path?: string, username?: string, password?: string, database?: int, connect_timeout?: float, read_timeout?: float, persistent?: bool}  $redis  What `PhpRedisConnector` is given.
     * @param  bool|list<string>  $allowedClasses  The classes `unserialize()` may rebuild: any, none, or a list.
     * @param  list<string>  $globalGroups  Groups shared by every site of a network; a name may end with `*`.
     * @param  list<string>  $nonPersistentGroups  Groups kept out of Redis; a name may end with `*`.
     * @param  list<string>  $prefetchExcludeGroups  Groups the prefetch never loads early; a name may end with `*`.
     */
    public function __construct(
        public array $redis,
        public ?string $prefix,
        public string $serializer,
        public bool|array $allowedClasses,
        public int $maxTtl,
        public array $globalGroups,
        public array $nonPersistentGroups,
        public bool $prefetchEnabled,
        public int $prefetchMaxKeys,
        public int $prefetchTtl,
        public array $prefetchExcludeGroups,
    ) {}

    /**
     * Read the settings from an array, with a default for every key it leaves out.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $prefetch = is_array($config['prefetch'] ?? null) ? $config['prefetch'] : [];
        $allowed = $config['allowed_classes'] ?? true;
        $prefix = $config['prefix'] ?? null;

        return new self(
            self::redis(is_array($config['redis'] ?? null) ? $config['redis'] : []),
            is_string($prefix) && trim($prefix) !== '' ? $prefix : null,
            (string) ($config['serializer'] ?? ValueCodec::SERIALIZER_PHP),
            is_array($allowed) ? self::names($allowed) : (bool) $allowed,
            (int) ($config['max_ttl'] ?? 0),
            self::names($config['global_groups'] ?? []),
            self::names($config['non_persistent_groups'] ?? []),
            (bool) ($prefetch['enabled'] ?? false),
            (int) ($prefetch['max_keys'] ?? 300),
            (int) ($prefetch['ttl'] ?? 604800),
            self::names($prefetch['exclude_groups'] ?? ['userlogins']),
        );
    }

    /**
     * The connection parameters: a `url` fills what the other keys leave out, a socket `path` replaces host and port.
     *
     * @param  array<array-key, mixed>  $redis
     * @return array{host?: string, port?: int, path?: string, username?: string, password?: string, database?: int, connect_timeout?: float, read_timeout?: float, persistent?: bool}
     */
    private static function redis(array $redis): array
    {
        if (is_string($redis['url'] ?? null) && $redis['url'] !== '') {
            $redis = array_merge($redis, self::fromUrl($redis['url']));
        }

        $parameters = [
            'host' => (string) ($redis['host'] ?? '127.0.0.1'),
            'port' => (int) ($redis['port'] ?? 6379),
            'path' => $redis['path'] ?? null,
            'username' => $redis['username'] ?? null,
            'password' => $redis['password'] ?? null,
            'database' => (int) ($redis['database'] ?? 0),
            'connect_timeout' => (float) ($redis['connect_timeout'] ?? 1.0),
            'read_timeout' => (float) ($redis['read_timeout'] ?? 1.0),
            'persistent' => (bool) ($redis['persistent'] ?? false),
        ];

        /** @var array{host?: string, port?: int, path?: string, username?: string, password?: string, database?: int, connect_timeout?: float, read_timeout?: float, persistent?: bool} */
        return array_filter($parameters, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The parameters of a `redis://user:password@host:6379/2` URL.
     *
     * @return array<string, mixed>
     */
    private static function fromUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return [];
        }

        return array_filter([
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'username' => isset($parts['user']) ? urldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
            'database' => isset($parts['path']) && ctype_digit(ltrim($parts['path'], '/')) ? (int) ltrim($parts['path'], '/') : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The names in a list, without what is not one.
     *
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
