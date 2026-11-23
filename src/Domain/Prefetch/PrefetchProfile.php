<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Prefetch;

/**
 * The keys the pages of one kind tend to read, kept between requests.
 *
 * An entry is `[group, key, blog]`. A profile only grows towards its limit, then
 * changes only when a request brings enough keys it did not know: rewriting it on
 * every request would cost more than the round trips it saves.
 */
final class PrefetchProfile
{
    /**
     * The identity of an entry.
     */
    public static function id(string $group, int|string $key, int $blogId): string
    {
        return $group."\0".$key."\0".$blogId;
    }

    /**
     * Read the entries of a stored profile, dropping what is not an entry.
     *
     * @return list<array{0: string, 1: int|string, 2: int}>
     */
    public static function parse(mixed $stored): array
    {
        if (! is_array($stored) || ! is_array($stored['e'] ?? null)) {
            return [];
        }

        $entries = [];

        foreach ($stored['e'] as $entry) {
            if (is_array($entry) && isset($entry[0], $entry[1], $entry[2]) && is_string($entry[0]) && (is_string($entry[1]) || is_int($entry[1])) && is_int($entry[2])) {
                $entries[] = [$entry[0], $entry[1], $entry[2]];
            }
        }

        return $entries;
    }

    /**
     * The entries to store after a request, or null when the stored profile stays as it is.
     *
     * The keys this request read come first, then the ones the profile already held, up to `$limit`.
     *
     * @param  list<array{0: string, 1: int|string, 2: int}>  $stored
     * @param  list<array{0: string, 1: int|string, 2: int}>  $seen
     * @return list<array{0: string, 1: int|string, 2: int}>|null
     */
    public static function merge(array $stored, array $seen, int $limit): ?array
    {
        $known = array_flip(array_map(static fn (array $entry): string => self::id($entry[0], $entry[1], $entry[2]), $stored));
        $seenIds = array_flip(array_map(static fn (array $entry): string => self::id($entry[0], $entry[1], $entry[2]), $seen));
        $new = count(array_diff_key($seenIds, $known));

        if ($new === 0) {
            return null;
        }

        if (count($stored) >= $limit && $new < intdiv($limit, 5)) {
            return null;
        }

        $merged = $seen;

        foreach ($stored as $entry) {
            if (! array_key_exists(self::id($entry[0], $entry[1], $entry[2]), $seenIds)) {
                $merged[] = $entry;
            }
        }

        return array_slice($merged, 0, $limit);
    }
}
