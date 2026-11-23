<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pollora Object Cache
|--------------------------------------------------------------------------
|
| Merged under `pollora.object-cache`. The connection parameters are read from
| Laravel's `database.redis.<connection>`; only the keys below are specific to
| the object cache.
|
*/
return [
    /*
     * The `database.redis` connection to take host, port, credentials and
     * database from. `default` and not `cache`: Laravel's cache store flushes
     * its whole database on `cache:clear`, and the object cache must not share
     * a database with it unless you want one to empty the other.
     */
    'connection' => env('OBJECT_CACHE_REDIS_CONNECTION', 'default'),

    'redis' => [
        // A database of its own, when the connection's one is shared with something else.
        'database' => env('OBJECT_CACHE_REDIS_DATABASE'),
        // Seconds. Without a limit phpredis waits for the PHP socket timeout (60 s) when Redis stops answering.
        'connect_timeout' => (float) env('OBJECT_CACHE_CONNECT_TIMEOUT', 1.0),
        'read_timeout' => (float) env('OBJECT_CACHE_READ_TIMEOUT', 1.0),
        'persistent' => (bool) env('OBJECT_CACHE_PERSISTENT', false),
    ],

    /*
     * Identifies the site on a shared Redis, so two sites never read each
     * other's keys and a flush only empties this one. Left empty, it is derived
     * from the site URL, the database name and the table prefix, or taken from
     * WP_CACHE_KEY_SALT when the project defines it.
     */
    'prefix' => env('OBJECT_CACHE_PREFIX'),

    // How arrays and objects are written: `php` or `igbinary`.
    'serializer' => env('OBJECT_CACHE_SERIALIZER', 'php'),

    // Classes `unserialize()` may rebuild: true for any (plugins cache objects of their own), false for none, or a list of class names.
    'allowed_classes' => true,

    // The longest life of an entry, in seconds; 0 sets no limit.
    'max_ttl' => (int) env('OBJECT_CACHE_MAX_TTL', 0),

    /*
     * The `wordpress` cache driver (`'driver' => 'wordpress'` in a store of
     * config/cache.php) and `php artisan cache:clear`.
     */
    'laravel' => [
        // The object cache group holding what `Cache::store('wordpress')` writes.
        'group' => env('OBJECT_CACHE_LARAVEL_GROUP', 'laravel'),
        // Whether `cache:clear` also empties this site's object cache (only its keys, never the Redis database).
        'flush_with_cache_clear' => (bool) env('OBJECT_CACHE_FLUSH_WITH_CACHE_CLEAR', true),
    ],

    /*
     * Prefetch: pages of one kind (the first segment of the path, signed in or not)
     * remember the keys they read, and the next one loads them with a single call to
     * Redis instead of one per key. Off by default. Only pages of the front, fetched
     * with GET or HEAD, take part; a page that is not answered with a 200 writes nothing.
     */
    'prefetch' => [
        'enabled' => (bool) env('OBJECT_CACHE_PREFETCH', false),
        // The most keys a profile holds, and the most one page records.
        'max_keys' => (int) env('OBJECT_CACHE_PREFETCH_MAX_KEYS', 300),
        // Seconds a profile lives without being rewritten.
        'ttl' => (int) env('OBJECT_CACHE_PREFETCH_TTL', 604800),
        // Groups never loaded early, because a value read a moment too soon would be wrong. A name may end with `*`.
        'exclude_groups' => ['userlogins'],
    ],

    // Groups shared by every site of a network, and groups kept out of Redis. A name may end with `*`.
    'global_groups' => [],
    'non_persistent_groups' => [],
];
