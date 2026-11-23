<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Laravel;

use Illuminate\Contracts\Config\Repository;
use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Domain\GroupRegistry;
use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchPlan;
use Pollora\ObjectCache\Domain\TtlPolicy;
use Pollora\ObjectCache\Infrastructure\Redis\RedisCacheStore;
use Pollora\ObjectCache\Infrastructure\Redis\ValueCodec;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;
use Pollora\ObjectCache\Infrastructure\WordPress\RequestSignature;

/**
 * Builds the object cache from the `pollora.object-cache` configuration: Redis
 * behind, a request cache in front.
 */
final class ObjectCacheFactory
{
    /**
     * @param  bool  $multisite  Whether keys carry the blog ID.
     * @param  int  $blogId  The blog the cache starts on.
     * @param  string|null  $salt  `WP_CACHE_KEY_SALT`, when the project defines it.
     * @param  string|null  $requestKind  The kind of page being served (see `RequestSignature`), or null when this request is not one the prefetch takes part in.
     */
    public static function make(Repository $config, bool $multisite = false, int $blogId = 1, ?string $salt = null, ?string $requestKind = null): ObjectCache
    {
        $groups = new GroupRegistry;
        $groups->addGlobal(self::names($config->get('pollora.object-cache.global_groups')));
        $groups->addNonPersistent(self::names($config->get('pollora.object-cache.non_persistent_groups')));

        $groups->addNonPrefetchable(self::names($config->get('pollora.object-cache.prefetch.exclude_groups')));

        $allowed = $config->get('pollora.object-cache.allowed_classes', true);

        $codec = new ValueCodec(
            (string) $config->get('pollora.object-cache.serializer', ValueCodec::SERIALIZER_PHP),
            is_array($allowed) ? array_values(array_filter($allowed, is_string(...))) : (bool) $allowed,
        );

        return new ObjectCache(
            new RedisCacheStore(ConnectorFactory::make($config), $codec),
            new InMemoryCacheStore,
            new KeyBuilder(SitePrefix::resolve($config, $salt), $multisite),
            $groups,
            new TtlPolicy((int) $config->get('pollora.object-cache.max_ttl', 0)),
            $blogId,
            self::plan($config, $requestKind),
        );
    }

    /**
     * The prefetch plan of this request: none unless the prefetch is switched on and the request has a kind.
     */
    private static function plan(Repository $config, ?string $requestKind): ?PrefetchPlan
    {
        if ($requestKind === null || ! (bool) $config->get('pollora.object-cache.prefetch.enabled', false)) {
            return null;
        }

        $maxKeys = (int) $config->get('pollora.object-cache.prefetch.max_keys', 300);

        return $maxKeys > 0 ? new PrefetchPlan($requestKind, $maxKeys, (int) $config->get('pollora.object-cache.prefetch.ttl', 604800)) : null;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
