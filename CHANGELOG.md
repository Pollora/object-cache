# Changelog

All notable changes to `pollora/object-cache` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A Redis host that does not resolve no longer prints a raw PHP warning (`Redis::connect(): php_network_getaddresses…`) in the console, the logs or, with errors displayed, the page. The cache already degrades to the request cache and says why in the error log.

## [0.3.2] - 2026-11-27

### Fixed

- `wp_cache_delete_multiple()` given a key that is not an integer or a string (an array, for example) reports it through `_doing_it_wrong()` and carries on; it used to stop the request with a `TypeError` while building its result.

## [0.3.1] - 2026-11-24

### Fixed

- The drop-in no longer stops the whole site when the Composer autoloader it names exists but the web server cannot open it: it tested `is_file()` and then `require_once` failed with a fatal error. It tests `is_readable()`, stays inert, and WordPress keeps its own cache.

### Added

- The drop-in says in the PHP error log, by path, when the autoloader it names cannot be read by the web server (for example a package installed with `wp package install`, which lands in the home directory of the user who ran it). Until now the cache stayed off without a word.
- `status` (`wp object-cache status`, `pollora:object-cache:status`) has an `Autoloader` line: the path, or that there is none, and whether this user can read it. A path in the home directory of the user who runs the command is flagged, since the web server usually cannot read it, and an autoloader that cannot be read makes `status` fail. `status` cannot test the web server's own account.

## [0.3.0] - 2026-11-24

### Changed

- **BREAKING**: the package no longer needs Laravel, and no longer contains anything of it. It requires PHP only and runs on a classic WordPress or Bedrock site. The service provider, the `wordpress` cache driver (`ObjectCacheStore`), the `cache:clear` and queue hooks, the Artisan commands and the Laravel configuration reading (`database.redis`, `pollora.object-cache.*`) move to the Pollora framework, which ships them.
- The settings are one array: the `WP_OBJECT_CACHE_CONFIG` constant of `wp-config.php`, or what a framework registers with `Configuration::provide()`. `config/object-cache.php` lists the keys with their defaults (`redis.*` replaces `connection`; the `laravel.*` keys belong to the framework).
- The key prefix of a site without a configured prefix comes from `WP_CACHE_KEY_SALT`, else from `DB_NAME`, `DB_HOST` and the table prefix (it no longer needs `app.url`, and never reads the database).
- The drop-in does not use Laravel's container any more, and carries the path of the site's Composer autoloader (drop-in version 2: run `enable` again to update it).

### Added

- `wp object-cache enable|disable|status|flush` (WP-CLI), declared by the package's autoloader when the process is WP-CLI.
- `Manager`, `Settings`, `Configuration`: what both front-ends (WP-CLI here, Artisan in the framework) call.

## [0.2.1] - 2026-11-24

### Changed

- `DropInInstaller` throws `DropInFileException` (a `RuntimeException`) when it cannot write or remove `object-cache.php`, with a message that says what to check, instead of a bare `RuntimeException`
- The prefetch's first-segment pattern is anchored with `\z`, so a path segment followed by a line break is no longer taken for a valid one
- `unserialize()` in `ValueCodec` carries a `nosemgrep` that says why it is there: the bytes are what the package wrote to its own keys, and `allowed_classes` decides which classes are rebuilt
- `DropIn::report()` takes the HTTP status of the response as an optional argument, and `PhpRedisConnector::credentials()` gives what `AUTH` receives, so both can be tested

### Fixed

- Line coverage as the harness counts it is back to 100 % (the failure paths of the prefetch and the plan built by the factory were not covered), and the mutation score is back to 96 % (49 survivors out of 1 242), the quality gates now being blocking (`enforce`)

## [0.2.0] - 2026-11-23

### Added

- Prefetch (off by default, `prefetch.enabled`): the pages of one kind of the front (first path segment, blog, signed in or not) remember the keys they read, and the next one loads them with one call to Redis. Profiles are bounded (`prefetch.max_keys`, `prefetch.ttl`), flushed with the site, rewritten only when a request brings enough new keys, and never loaded for non-persistent groups or `prefetch.exclude_groups` (`userlogins` by default; other packages add theirs through the configuration). Console, administration, AJAX, REST, cron and non-GET requests take no part. A value that holds an object of a class not loaded yet is left out and read normally later

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
