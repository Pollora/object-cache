<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain;

use InvalidArgumentException;

/**
 * Builds the key a value is stored under, and the prefixes a flush targets.
 *
 * A key reads `<site>:<group>:<blog><key>`, each part URL-encoded so a colon in
 * a group or a key can never pass for a separator. The site prefix keeps two
 * sites on one Redis apart. The group comes before the blog, as in WordPress, so
 * flushing a group empties it for every blog of a network. A blog part (`2:`)
 * exists only in a multisite and only for groups that are not global.
 */
final readonly class KeyBuilder
{
    private string $scope;

    /**
     * @param  string  $sitePrefix  Identifies the site on a shared Redis; never empty.
     * @param  bool  $multisite  Whether keys carry the blog ID.
     *
     * @throws InvalidArgumentException When the site prefix is empty.
     */
    public function __construct(string $sitePrefix, private bool $multisite = false)
    {
        if (trim($sitePrefix) === '') {
            throw new InvalidArgumentException('The site prefix of the object cache must not be empty.');
        }

        $this->scope = rawurlencode($sitePrefix).':';
    }

    /**
     * Whether WordPress accepts the value as a cache key: an integer or a
     * string that is not blank.
     */
    public static function isValidKey(mixed $key): bool
    {
        return is_int($key) || (is_string($key) && trim($key) !== '');
    }

    /**
     * The group a call lands in: an empty name means `default`, as in WordPress.
     */
    public static function normalizeGroup(string $group): string
    {
        return $group === '' ? 'default' : $group;
    }

    /**
     * The prefix shared by every key of the site, which a full flush removes.
     */
    public function siteScope(): string
    {
        return $this->scope;
    }

    /**
     * The prefix shared by every key of a group, in every blog.
     */
    public function groupScope(string $group): string
    {
        return $this->scope.rawurlencode(self::normalizeGroup($group)).':';
    }

    /**
     * The key of one value.
     *
     * @param  bool  $global  Whether the group is shared by every blog.
     */
    public function key(string $group, int|string $key, bool $global, int $blogId): string
    {
        $blog = $this->multisite && ! $global ? $blogId.':' : '';

        return $this->groupScope($group).$blog.rawurlencode((string) $key);
    }
}
