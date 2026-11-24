<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

/**
 * What the running PHP process says about the WordPress it serves: the constants of
 * `wp-config.php`, the blog, the request. Read once, so the code that uses it can be tested
 * with any values.
 */
final readonly class Environment
{
    /**
     * @param  array<string, mixed>  $server  `$_SERVER`
     * @param  array<string, mixed>  $cookies  `$_COOKIE`
     * @param  bool  $dynamic  Whether the request declared itself an administration, AJAX, cron, REST or XML-RPC one.
     * @param  string|null  $contentDir  `WP_CONTENT_DIR`, or the `wp-content` directory under `ABSPATH`.
     */
    public function __construct(
        public array $server,
        public array $cookies,
        public string $sapi,
        public bool $dynamic,
        public ?string $salt,
        public string $database,
        public string $host,
        public string $tablePrefix,
        public bool $multisite,
        public int $blogId,
        public ?string $contentDir,
    ) {}

    /**
     * Read the running WordPress.
     *
     * @codeCoverageIgnore A thin reading of constants and globals: everything that uses the values is tested with made-up ones.
     */
    public static function current(): self
    {
        $constant = static fn (string $name): ?string => defined($name) && is_string(constant($name)) ? constant($name) : null;
        $table = $GLOBALS['table_prefix'] ?? '';
        $content = $constant('WP_CONTENT_DIR') ?? ($constant('ABSPATH') !== null ? $constant('ABSPATH').'wp-content' : null);

        return new self(
            $_SERVER,
            $_COOKIE,
            PHP_SAPI,
            defined('WP_ADMIN') || defined('DOING_AJAX') || defined('DOING_CRON') || defined('REST_REQUEST') || defined('XMLRPC_REQUEST'),
            $constant('WP_CACHE_KEY_SALT'),
            $constant('DB_NAME') ?? '',
            $constant('DB_HOST') ?? '',
            is_string($table) ? $table : '',
            function_exists('is_multisite') && is_multisite(),
            function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            $content,
        );
    }
}
