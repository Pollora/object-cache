<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Management\Manager;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/oc-manager-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->source = $this->dir.'/source.php';
    file_put_contents($this->source, "<?php\n// ".DropInInstaller::MARKER." 2\n");
    $this->manager = new Manager(new DropInInstaller($this->source, $this->dir.'/object-cache.php'));
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->dir);
});

function liveSettings(): Settings
{
    return Settings::fromArray(['prefix' => 'manager-test', 'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => RedisHarness::database()]]);
}

it('installs and removes the drop-in, and says what stood there before', function (): void {
    expect($this->manager->enable())->toBe(DropInStatus::Missing)
        ->and($this->manager->enable())->toBe(DropInStatus::Current)
        ->and($this->manager->disable())->toBe(DropInStatus::Current)
        ->and($this->manager->disable())->toBe(DropInStatus::Missing);
});

it('says what is not configured, and what is', function (): void {
    $none = $this->manager->status(null);

    expect($none->healthy)->toBeFalse()
        ->and($none->lines['Redis'])->toBe('not configured: define WP_OBJECT_CACHE_CONFIG')
        ->and($none->lines['Key prefix'])->toBe('-')
        ->and($none->lines['Serializer'])->toBe('-')
        ->and($none->lines['Prefetch'])->toBe('off')
        ->and($none->lines['Drop-in'])->toBe('missing ('.$this->dir.'/object-cache.php)')
        ->and($none->lines['Used by this process'])->toBe('no');
});

it('is healthy when the drop-in is current, phpredis is loaded and Redis answers', function (): void {
    (new RedisHarness)->store();
    $this->manager->enable();
    $report = $this->manager->status(liveSettings());

    expect($report->lines['Redis'])->toStartWith('reachable (Redis ')
        ->and($report->lines['Key prefix'])->toBe('manager-test')
        ->and($report->lines['Serializer'])->toBe('php')
        ->and($report->lines['Drop-in'])->toStartWith('current')
        ->and($report->healthy)->toBeTrue();
});

it('is not healthy without the drop-in, and reports a Redis that does not answer', function (): void {
    $settings = Settings::fromArray(['redis' => ['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.2], 'prefetch' => ['enabled' => true]]);
    $this->manager->enable();
    $report = $this->manager->status($settings);

    expect($report->healthy)->toBeFalse()
        ->and($report->lines['Redis'])->toStartWith('unreachable: ')
        ->and($report->lines['Prefetch'])->toBe('on');
});

it('empties this site only', function (): void {
    $store = (new RedisHarness)->store();
    $store->set('manager-test:posts:1k', 'v', 0);
    $store->set('another-site:posts:1k', 'v', 0);

    expect($this->manager->flush(liveSettings()))->toBeTrue()
        ->and($store->get('manager-test:posts:1k'))->toBeNull()
        ->and($store->get('another-site:posts:1k'))->not->toBeNull();
});

it('puts the drop-in in the content directory of WordPress, with the autoloader path of the site', function (): void {
    $manager = Manager::forWordPress();

    expect($manager->status(null)->lines['Drop-in'])->toContain('object-cache.php');
});
