# Pollora Object Cache

A persistent object cache for WordPress, built for [Pollora](https://pollora.dev), the Laravel framework for WordPress. It keeps what WordPress caches (options, posts, terms, queries) in Redis between requests, instead of rebuilding it from the database on every page.

> **Status: under construction.** The package holds the cache engine and its two stores, in memory and Redis. There is no `object-cache.php` drop-in and no Artisan command yet, so installing the package does not change a site. Do not depend on its API before `0.1.0`.

## What it is

WordPress ships an object cache that lives for one request. A *persistent* object cache replaces it with a shared store, so the next request, and the next worker, finds the work already done. WordPress loads it from a drop-in file, `wp-content/object-cache.php`.

This package implements the whole contract of `WP_Object_Cache`: `add`, `get`, `set`, `replace`, `delete`, `incr`, `decr`, their `*_multiple` forms, `flush`, `flush_group`, `flush_runtime`, global and non-persistent groups, and multisite. It follows WordPress's own behavior, down to a stored `false` being a hit and a number never going below zero.

## How it is built

| Layer | Holds |
| --- | --- |
| `Domain` | The `CacheStore` contract, key building, cache groups (with `wc_cache_*`-style wildcards), time-to-live policy. No WordPress, no Redis, no Laravel. |
| `Application` | `ObjectCache`: every operation, over a persistent store and a request cache. |
| `Infrastructure` | The stores (in memory, and Redis with phpredis), the value format, the connector and the clock. |

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

## Planned

- `0.1.0`: the `object-cache.php` drop-in, the `pollora:object-cache:*` commands, a Laravel cache driver on the same store, a `pollora:doctor` check.
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
