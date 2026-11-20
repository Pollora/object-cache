<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Laravel;

use Illuminate\Contracts\Config\Repository;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;

/**
 * Reads the Redis connection of Laravel's configuration into a connector.
 *
 * Only the parameters of the connection are taken (where it is, who it is,
 * which database). Its `options` are ignored on purpose: Laravel's default
 * `options.prefix` and serializer are for Laravel's own use, and the object
 * cache needs a client with neither.
 */
final class ConnectorFactory
{
    /**
     * The connector for the connection that `pollora.object-cache.connection` names.
     */
    public static function make(Repository $config): PhpRedisConnector
    {
        return new PhpRedisConnector(self::parameters($config));
    }

    /**
     * The connection parameters the connector is given.
     *
     * @return array{host?: string, port?: int, path?: string, username?: string|null, password?: string|null, database?: int, connect_timeout?: float, read_timeout?: float, persistent?: bool}
     */
    public static function parameters(Repository $config): array
    {
        $name = (string) $config->get('pollora.object-cache.connection', 'default');
        $connection = $config->get('database.redis.'.$name, []);
        $connection = is_array($connection) ? $connection : [];

        $own = $config->get('pollora.object-cache.redis', []);
        $own = is_array($own) ? $own : [];

        if (is_string($connection['url'] ?? null) && $connection['url'] !== '') {
            $connection = array_merge($connection, self::fromUrl($connection['url']));
        }

        $database = $own['database'] ?? $connection['database'] ?? 0;

        return array_filter([
            'host' => $connection['host'] ?? '127.0.0.1',
            'port' => (int) ($connection['port'] ?? 6379),
            'path' => $connection['path'] ?? null,
            'username' => $connection['username'] ?? null,
            'password' => $connection['password'] ?? null,
            'database' => (int) $database,
            'connect_timeout' => (float) ($own['connect_timeout'] ?? 1.0),
            'read_timeout' => (float) ($own['read_timeout'] ?? 1.0),
            'persistent' => (bool) ($own['persistent'] ?? false),
        ], static fn (mixed $value): bool => $value !== null);
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
}
