<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/object-cache.png" width="100%" alt="Pollora Object Cache: a persistent Redis object cache for WordPress">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/object-cache"><img src="https://img.shields.io/packagist/v/pollora/object-cache" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/object-cache"><img src="https://img.shields.io/packagist/dt/pollora/object-cache" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/object-cache/actions/workflows/harness.yml"><img src="https://github.com/Pollora/object-cache/actions/workflows/harness.yml/badge.svg" alt="Harness"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/object-cache" alt="License"></a>
</p>

A persistent object cache for WordPress, built for [Pollora](https://pollora.dev), the Laravel framework for WordPress. It keeps what WordPress caches (options, posts, terms, queries) in Redis between requests, instead of rebuilding it from the database on every page.

> **Status: early release (`0.2`).** The engine, the Redis store, the `object-cache.php` drop-in, the Artisan commands and the Laravel cache driver are in, and the test suite runs against a real Redis in CI. Installing the package changes nothing until you run `php artisan pollora:object-cache:enable`. It has not run in production yet, and the API may change before `1.0`: pin the version.

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

The package needs PHP and phpredis, nothing else: it runs on a classic WordPress, on Bedrock, and inside a Pollora project, where the framework configures it (see below).

```bash
composer require pollora/object-cache
wp object-cache enable    # writes object-cache.php in the content directory
wp object-cache status    # drop-in, phpredis, Redis, key prefix
wp object-cache flush     # empties this site's keys, nothing else
wp object-cache disable   # removes the drop-in
```

`enable` and `disable` are idempotent. They only touch a drop-in this package wrote: another object cache's `object-cache.php` is left alone, and `enable --force` replaces it. A drop-in from an older version is updated by running `enable` again. The drop-in carries the path of the site's `vendor/autoload.php`, so a WordPress that does not load Composer's autoloader itself can still find the package.

If phpredis is not loaded, or nothing configures the cache, the drop-in defines nothing and WordPress runs on its own in-memory cache, with no error. If Redis stops answering during a request, the request carries on from its request cache, stops asking Redis, and the failure goes to the PHP error log once.

### Configuration

Define the settings you change as an array in `wp-config.php`, before `wp-settings.php` is loaded. Every key is optional; `config/object-cache.php` lists them with their defaults.

```php
define('WP_OBJECT_CACHE_CONFIG', [
    'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 5],
    'prefetch' => ['enabled' => true, 'exclude_groups' => ['userlogins', 'wc_session_id', 'wc_cache_*']],
]);
```

| Key | Default |
| --- | --- |
| `redis.url`, `redis.host`, `redis.port`, `redis.path` (socket) | `127.0.0.1`, `6379` |
| `redis.username`, `redis.password`, `redis.database` | none, none, `0` |
| `redis.connect_timeout`, `redis.read_timeout` | `1.0` seconds |
| `redis.persistent` | `false` |
| `prefix` | `WP_CACHE_KEY_SALT`, else derived from `DB_NAME`, `DB_HOST` and the table prefix |
| `serializer` | `php` (or `igbinary`) |
| `allowed_classes` | `true`: any class (or `false`, or a list) |
| `max_ttl` | `0`, no limit |
| `global_groups`, `non_persistent_groups` | `[]`; a name may end with `*` |
| `prefetch.enabled`, `prefetch.max_keys`, `prefetch.ttl` | `false`, `300`, `604800` seconds |
| `prefetch.exclude_groups` | `['userlogins']`; a name may end with `*` |

- **Use a database of its own** (`redis.database`) if the default one is shared with something else.
- **The key prefix** tells sites apart on a shared Redis, and a flush only removes this site's keys. It never needs the database: the cache is built before WordPress can read an option.
- **In a Pollora project**, the framework registers its own settings source (`Configuration::provide()`), built from `config/database.php` and `config/object-cache.php`, and offers `Cache::store('wordpress')`, `pollora:object-cache:*` and the `cache:clear` hook. None of that lives in this package.

## Prefetch

A WordPress page reads a hundred keys or more, one call to Redis each. With the prefetch on (`OBJECT_CACHE_PREFETCH=true`), the pages of one kind remember what they read, and the next one loads those keys with a single call.

- **A kind of page** is the first segment of the path (`/shop/...`, `/blog/...`, the home page), the blog of a network, and whether the visitor is signed in. The query string does not count.
- **Which requests take part**: pages of the front, fetched with GET or HEAD. Console commands, the administration, AJAX, REST, cron and form submissions neither load nor record anything. A page that is not answered with a 200 writes nothing.
- **The profile** is written when a request read keys it did not know, up to `max_keys`, and expires after `ttl` seconds without a rewrite. It lives under the site prefix, so `flush` removes it too. A page that brings few new keys does not rewrite a full profile.
- **What is never loaded early**: non-persistent groups, `prefetch.exclude_groups`, and what a request wrote before its first read. A key changed or deleted since the profile was written is read from Redis as of the request, so the prefetch cannot serve an older value than a normal read would.
- **Groups where a value read a moment too soon would be wrong** (sessions, logins) belong in `prefetch.exclude_groups`. Another package adds its own from a service provider's `register()`: `config()->push('pollora.object-cache.prefetch.exclude_groups', 'session_*')`.
- **A value that holds an object whose class is not loaded yet** (the first read of a request comes before WordPress has loaded all its classes) is left out of the prefetch, and read normally when it is asked for.
- If Redis stops answering while the profile is loaded, the request degrades as for any read.

It is off by default: turn it on, measure the number of Redis commands per page (`redis-cli INFO commandstats`), and keep it if it falls. On a development site (WordPress 7, WooCommerce, a Blade theme), once the profiles were written, the commands per request went from about 230 to 100-130 on the home page, from 203 to 126 on the shop page, and from 168 to 52 on a product page. A WooCommerce cart (add twice, read again, a second visitor) and a checkout through the Store API gave the same results with the prefetch on as without it.

## Planned

- Later: `alloptions` split into a hash, asynchronous flush, read replicas.

## Installation

Installing it changes nothing until the drop-in is enabled:

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

## Documentation

The package in a Pollora project: [Object Cache](https://pollora.dev/advanced/object-cache/).

## Contributing

Contributions are welcome: see the [contributing guide](https://github.com/Pollora/.github/blob/main/CONTRIBUTING.md). Report security issues privately, as described in the [security policy](https://github.com/Pollora/.github/blob/main/SECURITY.md).

## License

Pollora Object Cache is open-source software licensed under the [MIT license](LICENSE). © [RuBee group](https://rubee.group)
