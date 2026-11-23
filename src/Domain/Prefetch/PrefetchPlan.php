<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Domain\Prefetch;

/**
 * What the prefetch of one request is allowed to do.
 *
 * A request that has no plan (a console command, an administration page, an
 * AJAX call, a POST) neither loads nor records anything.
 */
final readonly class PrefetchPlan
{
    /**
     * @param  string  $signature  Names the profile this request reads and writes: pages of the same kind share one.
     * @param  int  $maxKeys  The most keys a profile holds, and the most one request records.
     * @param  int  $ttl  Seconds a profile lives without being rewritten.
     */
    public function __construct(
        public string $signature,
        public int $maxKeys = 300,
        public int $ttl = 604800,
    ) {}
}
