<?php

declare(strict_types=1);

use Pollora\ObjectCache\Application\ObjectCache;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

beforeEach(function (): void {
    $this->target = $this->contentDir.'/object-cache.php';
});

it('installs the drop-in, and says it is already there the second time', function (): void {
    (new RedisHarness)->store();

    $this->artisan('pollora:object-cache:enable')->expectsOutputToContain('Installed')->assertExitCode(0);
    expect(file_exists($this->target))->toBeTrue();

    $this->artisan('pollora:object-cache:enable')->expectsOutputToContain('Already installed')->assertExitCode(0);
});

it('updates an outdated drop-in', function (): void {
    (new RedisHarness)->store();
    file_put_contents($this->target, "<?php\n// ".DropInInstaller::MARKER." 0\n");

    $this->artisan('pollora:object-cache:enable')->expectsOutputToContain('Updated')->assertExitCode(0);
});

it('refuses to replace another object cache, unless forced', function (): void {
    (new RedisHarness)->store();
    file_put_contents($this->target, "<?php\n// another cache\n");

    $this->artisan('pollora:object-cache:enable')->expectsOutputToContain('belongs to another object cache')->assertExitCode(1);
    expect(file_get_contents($this->target))->toContain('another cache');

    $this->artisan('pollora:object-cache:enable', ['--force' => true])->expectsOutputToContain('Replaced another object cache')->assertExitCode(0);
    expect(file_get_contents($this->target))->toContain(DropInInstaller::MARKER);
});

it('warns, and still installs, when Redis does not answer', function (): void {
    (new RedisHarness)->store();
    config(['database.redis.default.port' => 1, 'pollora.object-cache.redis.connect_timeout' => 0.2]);

    $this->artisan('pollora:object-cache:enable')->expectsOutputToContain('Redis does not answer')->assertExitCode(0);
    expect(file_exists($this->target))->toBeTrue();
});

it('removes its own drop-in, and has nothing to do the second time', function (): void {
    copy(dirname(__DIR__, 2).'/resources/drop-in/object-cache.php', $this->target);

    $this->artisan('pollora:object-cache:disable')->expectsOutputToContain('Removed')->assertExitCode(0);
    expect(file_exists($this->target))->toBeFalse();

    $this->artisan('pollora:object-cache:disable')->expectsOutputToContain('Not installed')->assertExitCode(0);
});

it('never removes another object cache', function (): void {
    file_put_contents($this->target, "<?php\n// another cache\n");

    $this->artisan('pollora:object-cache:disable')->expectsOutputToContain('belongs to another object cache')->assertExitCode(1);
    expect(file_exists($this->target))->toBeTrue();
});

it('reports a healthy installation', function (): void {
    (new RedisHarness)->store();
    copy(dirname(__DIR__, 2).'/resources/drop-in/object-cache.php', $this->target);

    $this->artisan('pollora:object-cache:status')
        ->expectsOutputToContain('current')
        ->expectsOutputToContain('reachable (Redis')
        ->expectsOutputToContain('feature-test')
        ->assertExitCode(0);
});

it('reports a missing drop-in as a failure', function (): void {
    (new RedisHarness)->store();

    $this->artisan('pollora:object-cache:status')->expectsOutputToContain('missing')->assertExitCode(1);
});

it('reports an unreachable Redis', function (): void {
    (new RedisHarness)->store();
    copy(dirname(__DIR__, 2).'/resources/drop-in/object-cache.php', $this->target);
    config(['database.redis.default.port' => 1, 'pollora.object-cache.redis.connect_timeout' => 0.2]);

    $this->artisan('pollora:object-cache:status')->expectsOutputToContain('unreachable')->assertExitCode(1);
});

it('flushes the cache of the site and not the rest of Redis', function (): void {
    $harness = new RedisHarness;
    $store = $harness->store();
    $cache = $this->app->make(ObjectCache::class);
    $cache->set('k', 'v', 'g');
    $store->set('someone-else:g:k', 's:theirs', 0);

    $this->artisan('pollora:object-cache:flush')->expectsOutputToContain('empty')->assertExitCode(0);

    expect($this->app->make(ObjectCache::class)->get('k', 'g'))->toBeNull()
        ->and($store->get('someone-else:g:k'))->not->toBeNull();
});

it('fails to flush when Redis cannot be reached', function (): void {
    (new RedisHarness)->store();
    config(['database.redis.default.port' => 1, 'pollora.object-cache.redis.connect_timeout' => 0.2]);

    $this->artisan('pollora:object-cache:flush')->expectsOutputToContain('nothing was flushed')->assertExitCode(1);
});
