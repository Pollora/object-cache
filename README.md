# Pollora Object Cache

A persistent object cache for WordPress, built for [Pollora](https://pollora.dev), the Laravel framework for WordPress. It keeps what WordPress caches (options, posts, terms, queries) in Redis between requests, instead of rebuilding it from the database on every page.

> **Status: pre-release.** The engine, the Redis store, the `object-cache.php` drop-in and the Artisan commands are in. Installing the package changes nothing until you run `php artisan pollora:object-cache:enable`. Not tested on a real project yet: do not depend on its API before `0.1.0`.

## What it is

WordPress ships an object cache that lives for one request. A *persistent* object cache replaces it with a shared store, so the next request, and the next worker, finds the work already done. WordPress loads it from a drop-in file, `wp-content/object-cache.php`.

This package implements the whole contract of `WP_Object_Cache`: `add`, `get`, `set`, `replace`, `delete`, `incr`, `decr`, their `*_multiple` forms, `flush`, `flush_group`, `flush_runtime`, global and non-persistent groups, and multisite. It follows WordPress's own behavior, down to a stored `false` being a hit and a number never going below zero.

## How it is built

| Layer | Holds |
| --- | --- |
| `Domain` | The `CacheStore` contract, key building, cache groups (with `wc_cache_*`-style wildcards), time-to-live policy. No WordPress, no Redis, no Laravel. |
| `Application` | `ObjectCache`: every operation, over a persistent store and a request cache. |
| `Infrastructure` | The stores (in memory, and Redis with phpredis), the value format, the connector and the clock; the WordPress facade and the drop-in; the Laravel service provider, configuration and Artisan commands. |

Three decisions worth knowing:

- **A site never fails because of its cache.** If the persistent store cannot be reached, the cache keeps serving from the request cache, remembers the failure for the log, and does not try the store again during that request.
- **A flush only touches the site.** Keys carry a site prefix, and a flush removes that prefix, never the whole Redis: a Redis shared with other sites keeps their caches.
- **`add`, `replace` and `incr`/`decr` are primitives of the store**, not a read followed by a write, so two workers cannot race between the two steps.

## The Redis store

`RedisCacheStore` talks to Redis 4.0 or later with phpredis (6.x is the tested one; `ext-redis` is optional, see below). It opens its connection on the first command and gives up after one second by default, so a Redis that stops answering cannot hang a request.

- **A private client.** `PhpRedisConnector` builds a client of its own, with no serializer, no compression and no key prefix: the store builds its keys and writes its values itself, and would corrupt a client shared with anything else.
- **Values keep their type.** An integer is stored as plain decimal text (so Redis can add to it), other scalars carry a one-letter tag, and arrays and objects are serialized with PHP's serializer or igbinary and say which. A value that cannot be read back is a miss, never an exception. Objects are rebuilt of any class by default, because plugins cache objects of their own; a list of allowed classes can narrow that.
- **Atomic operations.** `add` is `SET NX`, `replace` is `SET XX`, and `incr`/`decr` run as one Lua script: integers are added by Redis itself (exact to the last bit), the result never goes below 0, the time to live is kept, and an integer that overflows becomes a float, as in PHP.
- **A flush is a scan.** It walks the keys of the site's prefix with `SCAN` and removes them with `UNLINK`; it never empties the database.

## Using it

```bash
composer require pollora/object-cache
php artisan pollora:object-cache:enable   # copies object-cache.php into the content directory
php artisan pollora:object-cache:status   # drop-in, phpredis, Redis, key prefix
php artisan pollora:object-cache:flush    # empties this site's keys, nothing else
php artisan pollora:object-cache:disable  # removes the drop-in
```

`enable` and `disable` are idempotent. They only touch a drop-in this package wrote: another object cache's `object-cache.php` is left alone, and `enable --force` replaces it. A drop-in from an older version is updated by running `enable` again.

If phpredis is not loaded, or the package is not installed, the drop-in defines nothing and WordPress runs on its own in-memory cache, with no error. If Redis stops answering during a request, the request carries on from its request cache, stops asking Redis, and the failure goes to the PHP error log once.

### Configuration

Publish it with `php artisan vendor:publish --tag=object-cache-config`, or set the environment variables. The settings live under `pollora.object-cache`; the connection itself (host, port, credentials) is read from Laravel's `database.redis`.

| Key | Environment | Default |
| --- | --- | --- |
| `connection` | `OBJECT_CACHE_REDIS_CONNECTION` | `default` |
| `redis.database` | `OBJECT_CACHE_REDIS_DATABASE` | the connection's |
| `redis.connect_timeout`, `redis.read_timeout` | `OBJECT_CACHE_CONNECT_TIMEOUT`, `OBJECT_CACHE_READ_TIMEOUT` | `1.0` seconds |
| `redis.persistent` | `OBJECT_CACHE_PERSISTENT` | `false` |
| `prefix` | `OBJECT_CACHE_PREFIX` | derived (below) |
| `serializer` | `OBJECT_CACHE_SERIALIZER` | `php` (or `igbinary`) |
| `allowed_classes` | | `true`: any class (or `false`, or a list) |
| `max_ttl` | `OBJECT_CACHE_MAX_TTL` | `0`, no limit |
| `global_groups`, `non_persistent_groups` | | `[]`; a name may end with `*` |
| `laravel.group` | `OBJECT_CACHE_LARAVEL_GROUP` | `laravel` |
| `laravel.flush_with_cache_clear` | `OBJECT_CACHE_FLUSH_WITH_CACHE_CLEAR` | `true` |

- **Use a database of its own.** The default connection is `default`, not `cache`: Laravel's cache store empties its whole Redis database on `cache:clear`, and the object cache would go with it. Point `OBJECT_CACHE_REDIS_DATABASE` at a database nothing else uses if `default` is shared.
- **The key prefix** tells sites apart on a shared Redis, and a flush only removes this site's keys. Left empty it is derived from the site URL, the database name and the table prefix, and `WP_CACHE_KEY_SALT` is honored when the project defines it. Every entry point (web, `artisan`, WP-CLI, queue workers) computes the same one, because it reads Laravel's configuration.
- **Queue workers**: the request cache is emptied before each job, so a job never starts from what the previous one cached, and a worker gets another chance at Redis after an outage.

## The Laravel cache driver

Declare a store with the `wordpress` driver in `config/cache.php`:

```php
'stores' => [
    'wordpress' => ['driver' => 'wordpress'],
],
```

`Cache::store('wordpress')` then reads and writes the object cache, in one group (`laravel` by default), under the same site prefix as `wp_cache_*`: a value put from Laravel is readable from WordPress with `wp_cache_get($key, 'laravel')`. Reads always go to Redis, never to the request cache, so a counter or a flag written by another process is seen. `Cache::add()` is atomic. Tags are not supported. The events (`CacheHit`, `KeyWritten`…) are those of Laravel's repository, enabled like for any store.

- **`Cache::flush()`** on this store empties its group only, never WordPress's own groups, and never the Redis database.
- **`php artisan cache:clear`** also empties this site's object cache (only its keys), unless `laravel.flush_with_cache_clear` is `false`. When the cleared store is the `wordpress` one, only its group goes.
- If Redis stops answering, reads miss and writes report `false`; nothing throws and `cache:clear` still succeeds.

## Planned

- `0.2.0`: prefetching by group, `alloptions` split into a hash, asynchronous flush, read replicas.

## Installation

Not yet useful on its own, but safe to require:

```bash
composer require pollora/object-cache
```

Requires PHP 8.3+. `ext-redis` is needed to reach Redis with phpredis, and `ext-igbinary` to use the igbinary serializer; both are optional, and without `ext-redis` the object cache will fall back to WordPress's in-memory cache.

## Development

```bash
composer install
composer qa     # every gate of the Pollora harness
```

The Redis tests need a Redis they may empty: they use database 15 (`OBJECT_CACHE_TEST_REDIS_DB`) of `REDIS_HOST`:`REDIS_PORT` (127.0.0.1:6379), refuse database 0, and are skipped when there is no Redis, except in CI, where they fail instead.

## License

MIT, see [LICENSE](LICENSE).
