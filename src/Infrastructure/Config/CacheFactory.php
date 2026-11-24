<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Config;

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Domain\GroupRegistry;
use Pollora\ObjectCache\Domain\KeyBuilder;
use Pollora\ObjectCache\Domain\Prefetch\PrefetchPlan;
use Pollora\ObjectCache\Domain\TtlPolicy;
use Pollora\ObjectCache\Infrastructure\Redis\PhpRedisConnector;
use Pollora\ObjectCache\Infrastructure\Redis\RedisCacheStore;
use Pollora\ObjectCache\Infrastructure\Redis\ValueCodec;
use Pollora\ObjectCache\Infrastructure\Stores\InMemoryCacheStore;

/**
 * Builds the object cache from its settings: Redis behind, a request cache in front.
 */
final class CacheFactory
{
    /**
     * @param  string  $prefix  The site prefix every key carries (see `SitePrefix`).
     * @param  bool  $multisite  Whether keys carry the blog ID.
     * @param  int  $blogId  The blog the cache starts on.
     * @param  string|null  $requestKind  The kind of page being served (see `RequestSignature`), or null when this request does not take part in the prefetch.
     */
    public static function make(Settings $settings, string $prefix, bool $multisite = false, int $blogId = 1, ?string $requestKind = null): ObjectCache
    {
        $groups = new GroupRegistry;
        $groups->addGlobal($settings->globalGroups);
        $groups->addNonPersistent($settings->nonPersistentGroups);
        $groups->addNonPrefetchable($settings->prefetchExcludeGroups);

        return new ObjectCache(
            new RedisCacheStore(new PhpRedisConnector($settings->redis), new ValueCodec($settings->serializer, $settings->allowedClasses)),
            new InMemoryCacheStore,
            new KeyBuilder($prefix, $multisite),
            $groups,
            new TtlPolicy($settings->maxTtl),
            $blogId,
            self::plan($settings, $requestKind),
        );
    }

    /**
     * The prefetch plan of this request: none unless the prefetch is on and the request has a kind.
     */
    private static function plan(Settings $settings, ?string $requestKind): ?PrefetchPlan
    {
        if ($requestKind === null || ! $settings->prefetchEnabled || $settings->prefetchMaxKeys <= 0) {
            return null;
        }

        return new PrefetchPlan($requestKind, $settings->prefetchMaxKeys, $settings->prefetchTtl);
    }
}
