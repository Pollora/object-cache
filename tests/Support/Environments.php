<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Infrastructure\WordPress\Environment;

/**
 * A made-up WordPress environment: a GET of /shop/a-product on blog 1, nothing signed in.
 */
final class Environments
{
    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $cookies
     */
    public static function make(
        array $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/shop/a-product'],
        array $cookies = [],
        string $sapi = 'fpm-fcgi',
        bool $dynamic = false,
        ?string $salt = null,
        string $database = 'db',
        string $host = 'dbhost',
        string $tablePrefix = 'wp_',
        bool $multisite = false,
        int $blogId = 1,
        ?string $contentDir = null,
    ): Environment {
        return new Environment($server, $cookies, $sapi, $dynamic, $salt, $database, $host, $tablePrefix, $multisite, $blogId, $contentDir);
    }
}
