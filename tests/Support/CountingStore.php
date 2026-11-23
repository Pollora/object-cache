<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;

/**
 * Wraps a store and counts the calls that read from it, to show a round trip saved.
 */
final class CountingStore implements CacheStore
{
    public int $gets = 0;

    public int $getManys = 0;

    public int $sets = 0;

    public function __construct(private readonly CacheStore $inner) {}

    public function get(string $key): ?CacheValue
    {
        $this->gets++;

        return $this->inner->get($key);
    }

    public function getMany(array $keys): array
    {
        $this->getManys++;

        return $this->inner->getMany($keys);
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $this->sets++;

        return $this->inner->set($key, $value, $ttl);
    }

    public function add(string $key, mixed $value, int $ttl): bool
    {
        return $this->inner->add($key, $value, $ttl);
    }

    public function replace(string $key, mixed $value, int $ttl): bool
    {
        return $this->inner->replace($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function adjust(string $key, int $delta): int|float|false
    {
        return $this->inner->adjust($key, $delta);
    }

    public function deleteByPrefix(string $prefix): int
    {
        return $this->inner->deleteByPrefix($prefix);
    }
}
