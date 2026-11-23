# Changelog

All notable changes to `pollora/object-cache` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-23

### Added

- `ObjectCache`: the operations of `WP_Object_Cache` (`add`, `get`, `set`, `replace`, `delete`, `incr`, `decr`, their multiple forms, `flush`, `flush_group`, `flush_runtime`, global and non-persistent groups, multisite) over a persistent store and a request cache; a failing persistent store degrades to the request cache instead of throwing
- The `CacheStore` contract, with `add`, `replace` and `adjust` as primitives of the store, and an in-memory store
- Cache groups with `*` wildcards, a site prefix that scopes every key and every flush, and a time-to-live policy
- A contract test suite that every store runs, and a conformance suite for the object cache
- `RedisCacheStore`, the persistent store on Redis (phpredis): `add` and `replace` as `SET NX` and `SET XX`, `incr`/`decr` as one Lua script (exact integers, floor at 0, time to live kept, overflow to float), a flush by `SCAN` and `UNLINK` on the site's prefix only
- `ValueCodec`, the value format: an integer as plain decimal text, a tag for other scalars, PHP or igbinary serialization for arrays and objects (tagged, so a change of serializer reads old values), an unreadable value is a miss
- `PhpRedisConnector`, a private phpredis client (no serializer, compression or prefix), lazy, with one-second connect and read timeouts by default
- The `object-cache.php` drop-in (`resources/drop-in`) and `WordPressObjectCache`, the `$wp_object_cache` object with the signatures and return values of `WP_Object_Cache`; every `wp_cache_*` function of the API is defined, and when phpredis or the package is missing the drop-in defines none, so WordPress keeps its own cache
- `pollora:object-cache:enable`, `:disable`, `:status` and `:flush`; `enable` and `disable` never touch another object cache's `object-cache.php` (`enable --force` replaces it)
- `ObjectCacheServiceProvider` and the `pollora.object-cache` configuration (`config/object-cache.php`): connection read from Laravel's `database.redis`, a key prefix every entry point computes the same way (`WP_CACHE_KEY_SALT` honored), serializer, allowed classes, maximum TTL, groups
- The request cache is emptied, and Redis tried again, before each queued job
- The contract suite also runs on a real Redis, with the PHP and igbinary serializers, and covers integer limits, numeric strings and floats
- The `wordpress` Laravel cache driver (`Cache::store('wordpress')`): `ObjectCacheStore` reads and writes the object cache in one group (`laravel.group`, default `laravel`), reads bypass the request cache, `Cache::add()` is atomic, `Cache::flush()` empties the group only
- `php artisan cache:clear` also empties the site's object cache (`laravel.flush_with_cache_clear`, default `true`), by its key prefix, never the Redis database

### Fixed

- A group whose name is a number (`'7'`) no longer raises a `TypeError` in the group registry
- `flushRuntime()` also resets the failure of the persistent store, so a long-lived worker does not stay cut off from Redis after one outage
