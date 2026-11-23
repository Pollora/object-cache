<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

/**
 * Tells which kind of page a request is, so the prefetch can keep one profile per kind.
 *
 * Only a page of the site's front, fetched with GET or HEAD, has a kind. A console
 * command, an administration page, an AJAX call, a REST request, a cron run or a
 * form submission has none and is left out of the prefetch.
 *
 * The kind is the first segment of the path, the blog and whether a visitor is signed
 * in: `/shop/a-product?x=1` and `/shop/` share a profile, a crawler asking for random
 * paths makes at most one profile per first segment, and the `404` pages it gets write
 * nothing.
 */
final class RequestSignature
{
    /** Paths WordPress answers with something other than a page of the front. */
    private const array NOT_A_PAGE = ['/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php', '/wp-comments-post.php'];

    /**
     * @param  array<string, mixed>  $server  `$_SERVER`
     * @param  array<string, mixed>  $cookies  `$_COOKIE`
     * @param  bool  $dynamic  Whether the request already declared itself an administration, AJAX, cron or REST one (`WP_ADMIN`, `DOING_AJAX`, `DOING_CRON`, `REST_REQUEST`).
     * @return string|null The kind, or null when the request takes no part in the prefetch.
     */
    public static function resolve(string $sapi, array $server, array $cookies, bool $dynamic, int $blogId): ?string
    {
        if ($dynamic || $sapi === 'cli' || $sapi === 'phpdbg') {
            return null;
        }

        $method = $server['REQUEST_METHOD'] ?? null;

        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $uri = $server['REQUEST_URI'] ?? null;
        $path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

        foreach (self::NOT_A_PAGE as $prefix) {
            if (str_contains($path, $prefix)) {
                return null;
            }
        }

        $segment = trim(explode('/', ltrim($path, '/'))[0]);
        $segment = $segment === '' ? '_home' : (preg_match('/^[a-z0-9_-]{1,40}$/i', $segment) === 1 ? strtolower($segment) : '_other');

        return 'front:'.$blogId.':'.$segment.(self::signedIn($cookies) ? ':member' : '');
    }

    /**
     * @param  array<string, mixed>  $cookies
     */
    private static function signedIn(array $cookies): bool
    {
        foreach (array_keys($cookies) as $name) {
            if (str_starts_with((string) $name, 'wordpress_logged_in_')) {
                return true;
            }
        }

        return false;
    }
}
