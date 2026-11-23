<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Infrastructure\WordPress\WordPressObjectCache;

it('merges the configuration under pollora.object-cache', function (): void {
    expect(config('pollora.object-cache.connection'))->toBe('default')
        ->and(config('pollora.object-cache.serializer'))->toBe('php')
        ->and(config('pollora.object-cache.max_ttl'))->toBe(0)
        ->and(config('pollora.object-cache.allowed_classes'))->toBeTrue()
        ->and(config('pollora.object-cache.global_groups'))->toBe([])
        ->and(config('pollora.object-cache.non_persistent_groups'))->toBe([])
        ->and(config('pollora.object-cache.redis.connect_timeout'))->toBe(1.0)
        ->and(config('pollora.object-cache.redis.read_timeout'))->toBe(1.0)
        ->and(config('pollora.object-cache.redis.persistent'))->toBeFalse();
});

it('keeps the settings a project already made', function (): void {
    expect(config('pollora.object-cache.prefix'))->toBe('feature-test');
});

it('binds the object cache and its WordPress facade, a new one each time', function (): void {
    $first = $this->app->make(WordPressObjectCache::class);

    expect($first)->toBeInstanceOf(WordPressObjectCache::class)
        ->and($first->engine())->toBeInstanceOf(ObjectCache::class)
        ->and($this->app->make(WordPressObjectCache::class))->not->toBe($first);
});

it('builds the cache for a multisite from the WordPress functions, and a salt from the project', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_current_blog_id')->justReturn(3);

    $cache = $this->app->make(ObjectCache::class);

    expect($cache)->toBeInstanceOf(ObjectCache::class);
});

it('publishes its configuration', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'object-cache-config', '--force' => true])->assertExitCode(0);

    expect(file_exists(config_path('object-cache.php')))->toBeTrue();

    unlink(config_path('object-cache.php'));
});

it('registers the four commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('pollora:object-cache:enable')
        ->toContain('pollora:object-cache:disable')
        ->toContain('pollora:object-cache:status')
        ->toContain('pollora:object-cache:flush');
});

it('empties the request cache before each queued job', function (): void {
    Functions\expect('wp_cache_flush_runtime')->once();

    Event::dispatch(new JobProcessing('sync', Mockery::mock(Job::class, ['payload' => []])));
});

it('has the prefetch off by default, with the login group left out of it', function (): void {
    expect(config('pollora.object-cache.prefetch.enabled'))->toBeFalse()
        ->and(config('pollora.object-cache.prefetch.max_keys'))->toBe(300)
        ->and(config('pollora.object-cache.prefetch.ttl'))->toBe(604800)
        ->and(config('pollora.object-cache.prefetch.exclude_groups'))->toBe(['userlogins']);
});

it('reads the groups other packages add to the prefetch exclusions when the cache is built', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_current_blog_id')->justReturn(1);
    config(['pollora.object-cache.prefetch.enabled' => true]);
    config()->push('pollora.object-cache.prefetch.exclude_groups', 'session_*');

    $cache = $this->app->make(ObjectCache::class);

    expect($cache)->toBeInstanceOf(ObjectCache::class);
});
