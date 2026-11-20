<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\Console;

use Illuminate\Console\Command;
use Pollora\ObjectCache\Application\ObjectCache;

/**
 * Empties the object cache of this site, and only this site.
 */
final class FlushCommand extends Command
{
    protected $signature = 'pollora:object-cache:flush';

    protected $description = 'Empty the persistent object cache of this site';

    public function handle(ObjectCache $cache): int
    {
        $cache->flush();

        if ($cache->isDegraded()) {
            $this->components->error('Redis could not be reached, nothing was flushed: '.$cache->failure()?->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The object cache of this site is empty.');

        return self::SUCCESS;
    }
}
