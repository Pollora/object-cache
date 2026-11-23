<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Tests\Support;

use Pollora\ObjectCache\Domain\CacheValue;
use Pollora\ObjectCache\Domain\Contracts\CacheStore;
use Pollora\ObjectCache\Domain\Exceptions\StoreUnavailableException;

/**
 * Wraps a store that works, until a test says its writes must fail.
 */
final class FlakyStore implements CacheStore
{
    public bool $failWrites = false;

    public int $getManys = 0;

    public function __construct(private readonly CacheStore $inner) {}

    public function get(string $key): ?CacheValue
    {
        return $this->inner->get($key);
    }

    public function getMany(array $keys): array
    {
        $this->getManys++;

        return $this->inner->getMany($keys);
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $this->write();

        return $this->inner->set($key, $value, $ttl);
    }

    public function add(string $key, mixed $value, int $ttl): bool
    {
        $this->write();

        return $this->inner->add($key, $value, $ttl);
    }

    public function replace(string $key, mixed $value, int $ttl): bool
    {
        $this->write();

        return $this->inner->replace($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $this->write();

        return $this->inner->delete($key);
    }

    public function adjust(string $key, int $delta): int|float|false
    {
        $this->write();

        return $this->inner->adjust($key, $delta);
    }

    public function deleteByPrefix(string $prefix): int
    {
        $this->write();

        return $this->inner->deleteByPrefix($prefix);
    }

    private function write(): void
    {
        if ($this->failWrites) {
            throw new StoreUnavailableException('writes are failing');
        }
    }
}
