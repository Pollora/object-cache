# Changelog

All notable changes to `pollora/object-cache` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ObjectCache`: the operations of `WP_Object_Cache` (`add`, `get`, `set`, `replace`, `delete`, `incr`, `decr`, their multiple forms, `flush`, `flush_group`, `flush_runtime`, global and non-persistent groups, multisite) over a persistent store and a request cache; a failing persistent store degrades to the request cache instead of throwing
- The `CacheStore` contract, with `add`, `replace` and `adjust` as primitives of the store, and an in-memory store
- Cache groups with `*` wildcards, a site prefix that scopes every key and every flush, and a time-to-live policy
- A contract test suite that every store runs, and a conformance suite for the object cache
