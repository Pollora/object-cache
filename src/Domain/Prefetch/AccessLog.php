<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Prefetch;

/**
 * The keys a request read from the persistent cache, in the order it first read
 * them, without repeats and up to a limit.
 */
final class AccessLog
{
    /** @var array<string, array{0: string, 1: int|string, 2: int}> */
    private array $entries = [];

    public function __construct(private readonly int $limit) {}

    /**
     * Note that the request read a key. Keys past the limit are ignored.
     */
    public function record(string $group, int|string $key, int $blogId): void
    {
        $id = PrefetchProfile::id($group, $key, $blogId);

        if (isset($this->entries[$id]) || count($this->entries) >= $this->limit) {
            return;
        }

        $this->entries[$id] = [$group, $key, $blogId];
    }

    /**
     * @return list<array{0: string, 1: int|string, 2: int}> `[group, key, blog]` of each key read.
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }
}
