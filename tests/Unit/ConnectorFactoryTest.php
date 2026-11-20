<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Pollora\ObjectCache\Infrastructure\Laravel\ConnectorFactory;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;

function connectionConfig(array $redis = [], array $own = [], string $connection = 'default'): Repository
{
    return new Repository([
        'database' => ['redis' => $redis],
        'pollora' => ['object-cache' => array_merge(['connection' => $connection], $own)],
    ]);
}

it('reads the parameters of the connection it names', function (): void {
    $config = connectionConfig([
        'default' => ['host' => 'a.test', 'port' => '6380', 'username' => 'u', 'password' => 'p', 'database' => '3'],
        'cache' => ['host' => 'b.test'],
    ]);

    expect(ConnectorFactory::parameters($config))->toMatchArray([
        'host' => 'a.test', 'port' => 6380, 'username' => 'u', 'password' => 'p', 'database' => 3,
    ])->and(ConnectorFactory::parameters(connectionConfig(['default' => ['host' => 'a.test'], 'cache' => ['host' => 'b.test']], connection: 'cache'))['host'])->toBe('b.test');
});

it('falls back to a local Redis when the connection is not configured', function (): void {
    expect(ConnectorFactory::parameters(connectionConfig()))->toBe([
        'host' => '127.0.0.1', 'port' => 6379, 'database' => 0,
        'connect_timeout' => 1.0, 'read_timeout' => 1.0, 'persistent' => false,
    ]);
});

it('takes a Unix socket', function (): void {
    expect(ConnectorFactory::parameters(connectionConfig(['default' => ['path' => '/run/redis.sock']])))->toHaveKey('path', '/run/redis.sock');
});

it('lets the object cache choose its database and timeouts', function (): void {
    $config = connectionConfig(
        ['default' => ['host' => 'a.test', 'database' => 1]],
        ['redis' => ['database' => '12', 'connect_timeout' => '0.5', 'read_timeout' => 2, 'persistent' => true]],
    );

    expect(ConnectorFactory::parameters($config))->toMatchArray([
        'database' => 12, 'connect_timeout' => 0.5, 'read_timeout' => 2.0, 'persistent' => true,
    ]);
});

it('never takes the options of the connection, whose prefix and serializer are Laravel\'s own', function (): void {
    $parameters = ConnectorFactory::parameters(connectionConfig(['options' => ['prefix' => 'app-'], 'default' => ['host' => 'a.test', 'options' => ['prefix' => 'x-', 'serializer' => 2]]]));

    expect($parameters)->not->toHaveKey('options')->not->toHaveKey('prefix');
});

it('reads a connection URL', function (): void {
    $config = connectionConfig(['default' => ['url' => 'redis://user%40x:p%3Ass@r.test:6390/4', 'host' => 'ignored.test']]);

    expect(ConnectorFactory::parameters($config))->toMatchArray([
        'host' => 'r.test', 'port' => 6390, 'username' => 'user@x', 'password' => 'p:ss', 'database' => 4,
    ]);
});

it('reads a connection URL without credentials or database', function (): void {
    $parameters = ConnectorFactory::parameters(connectionConfig(['default' => ['url' => 'redis://r.test', 'database' => 2]]));

    expect($parameters)->toMatchArray(['host' => 'r.test', 'port' => 6379, 'database' => 2])
        ->not->toHaveKey('username')->not->toHaveKey('password');
});

it('ignores a URL it cannot parse, and a database that is not a number', function (): void {
    expect(ConnectorFactory::parameters(connectionConfig(['default' => ['url' => 'redis:///', 'host' => 'a.test']]))['host'])->toBe('a.test')
        ->and(ConnectorFactory::parameters(connectionConfig(['default' => ['url' => 'redis://r.test/abc', 'database' => 5]]))['database'])->toBe(5);
});

it('builds the connector', function (): void {
    expect(ConnectorFactory::make(connectionConfig()))->toBeInstanceOf(PhpRedisConnector::class);
});
