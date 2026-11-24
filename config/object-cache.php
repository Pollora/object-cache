<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pollora Object Cache
|--------------------------------------------------------------------------
|
| The settings, with their defaults. Every key is optional.
|
| On a classic WordPress or Bedrock site, define the ones you change as an array
| in wp-config.php, before wp-settings.php is loaded:
|
|     define('WP_OBJECT_CACHE_CONFIG', [
|         'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 5],
|         'prefetch' => ['enabled' => true],
|     ]);
|
| In a Pollora project, the framework builds the same array from `config/database.php`
| and `config/object-cache.php` of the project.
|
*/
return [
    'redis' => [
        // `redis://user:password@host:6379/2`: fills what the keys below leave out.
        'url' => null,
        'host' => '127.0.0.1',
        'port' => 6379,
        // A Unix socket: replaces host and port.
        'path' => null,
        'username' => null,
        'password' => null,
        // A database of its own: the cache of this site is empty after `flush`, never the database.
        'database' => 0,
        // Seconds. Without a limit phpredis waits for the PHP socket timeout (60 s) when Redis stops answering.
        'connect_timeout' => 1.0,
        'read_timeout' => 1.0,
        'persistent' => false,
    ],

    /*
     * Identifies the site on a shared Redis, so two sites never read each other's
     * keys and a flush only empties this one. Left empty it is WP_CACHE_KEY_SALT
     * when the project defines it, else derived from DB_NAME, DB_HOST and the table prefix.
     */
    'prefix' => null,

    // How arrays and objects are written: `php` or `igbinary`.
    'serializer' => 'php',

    // Classes `unserialize()` may rebuild: true for any (plugins cache objects of their own), false for none, or a list of class names.
    'allowed_classes' => true,

    // The longest life of an entry, in seconds; 0 sets no limit.
    'max_ttl' => 0,

    // Groups shared by every site of a network, and groups kept out of Redis. A name may end with `*`.
    'global_groups' => [],
    'non_persistent_groups' => [],

    /*
     * Prefetch: pages of one kind (the first segment of the path, signed in or not)
     * remember the keys they read, and the next one loads them with a single call to
     * Redis instead of one per key. Off by default. Only pages of the front, fetched
     * with GET or HEAD, take part; a page that is not answered with a 200 writes nothing.
     */
    'prefetch' => [
        'enabled' => false,
        // The most keys a profile holds, and the most one page records.
        'max_keys' => 300,
        // Seconds a profile lives without being rewritten.
        'ttl' => 604800,
        // Groups never loaded early, because a value read a moment too soon would be wrong
        // (sessions, logins). A name may end with `*`. WooCommerce: add 'wc_session_id' and 'wc_cache_*'.
        'exclude_groups' => ['userlogins'],
    ],
];
