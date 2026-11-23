# Pollora Object Cache

A persistent object cache for WordPress, built for [Pollora](https://pollora.dev), the Laravel framework for WordPress. It keeps what WordPress caches (options, posts, terms, queries) in Redis between requests, instead of rebuilding it from the database on every page.

> **Status: under construction.** This release holds the engine only: the cache logic and an in-memory store. There is no Redis adapter, no `object-cache.php` drop-in and no Artisan command yet, so installing the package does not change a site. Do not depend on its API before `0.1.0`.

## What it is

WordPress ships an object cache that lives for one request. A *persistent* object cache replaces it with a shared store, so the next request, and the next worker, finds the work already done. WordPress loads it from a drop-in file, `wp-content/object-cache.php`.

This package implements the whole contract of `WP_Object_Cache`: `add`, `get`, `set`, `replace`, `delete`, `incr`, `decr`, their `*_multiple` forms, `flush`, `flush_group`, `flush_runtime`, global and non-persistent groups, and multisite. It follows WordPress's own behavior, down to a stored `false` being a hit and a number never going below zero.

## How it is built

| Layer | Holds |
| --- | --- |
| `Domain` | The `CacheStore` contract, key building, cache groups (with `wc_cache_*`-style wildcards), time-to-live policy. No WordPress, no Redis, no Laravel. |
| `Application` | `ObjectCache`: every operation, over a persistent store and a request cache. |
| `Infrastructure` | Stores and clock. Today: an in-memory store. Next: Redis. |

Three decisions worth knowing:

- **A site never fails because of its cache.** If the persistent store cannot be reached, the cache keeps serving from the request cache, remembers the failure for the log, and does not try the store again during that request.
- **A flush only touches the site.** Keys carry a site prefix, and a flush removes that prefix, never the whole Redis: a Redis shared with other sites keeps their caches.
- **`add`, `replace` and `incr`/`decr` are primitives of the store**, not a read followed by a write, so two workers cannot race between the two steps.

## Planned

- `0.1.0`: the Redis store (phpredis), the `object-cache.php` drop-in, the `pollora:object-cache:*` commands, a Laravel cache driver on the same store, a `pollora:doctor` check.
- `0.2.0`: prefetching by group, `alloptions` split into a hash, asynchronous flush, read replicas.

## Installation

Not yet useful on its own, but safe to require:

```bash
composer require pollora/object-cache
```

Requires PHP 8.3+. `ext-redis` will be needed to reach Redis with phpredis; without it the object cache will fall back to WordPress's in-memory cache.

## Development

```bash
composer install
composer qa     # every gate of the Pollora harness
```

## License

MIT, see [LICENSE](LICENSE).
