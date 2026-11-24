<?php

declare(strict_types=1);

use Pollora\ObjectCache\Infrastructure\Config\Settings;
use Pollora\ObjectCache\Infrastructure\Management\Manager;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInInstaller;
use Pollora\ObjectCache\Infrastructure\WordPress\DropInStatus;
use Pollora\ObjectCache\Tests\Support\Environments;
use Pollora\ObjectCache\Tests\Support\RedisHarness;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/oc-manager-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->source = $this->dir.'/source.php';
    file_put_contents($this->source, "<?php\n// ".DropInInstaller::MARKER." 2\n");
    $this->manager = new Manager(new DropInInstaller($this->source, $this->dir.'/object-cache.php'), Environments::make());
});

afterEach(function (): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
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

it('says nothing was emptied when Redis cannot be reached', function (): void {
    $dead = Settings::fromArray(['prefix' => 'manager-test', 'redis' => ['port' => 1, 'connect_timeout' => 0.2]]);

    expect($this->manager->flush($dead))->toBeFalse();
});

/**
 * A manager whose drop-in names the given autoloader, in an environment of the given home directory.
 */
function managerWithAutoload(string $dir, string $source, string $autoload, ?string $home = null): Manager
{
    return new Manager(new DropInInstaller($source, $dir.'/object-cache.php', $autoload), Environments::make(server: $home === null ? [] : ['HOME' => $home]));
}

it('says there is no autoloader to load when the drop-in names none', function (): void {
    $report = $this->manager->status(null);

    expect($report->lines['Autoloader'])->toBe('none (WordPress must load the package itself)');
});

it('names a readable autoloader, and does not blame a home directory it is not in', function (): void {
    $autoload = $this->dir.'/vendor/autoload.php';
    mkdir($this->dir.'/vendor');
    file_put_contents($autoload, '<?php');
    $elsewhere = managerWithAutoload($this->dir, $this->source, $autoload, '/home/someone-else');
    $nowhere = managerWithAutoload($this->dir, $this->source, $autoload);

    expect($elsewhere->status(null)->lines['Autoloader'])->toBe($autoload)
        ->and($nowhere->status(null)->lines['Autoloader'])->toBe($autoload)
        ->and(managerWithAutoload($this->dir, $this->source, $autoload, '')->status(null)->lines['Autoloader'])->toBe($autoload);
});

it('warns about an autoloader in the home directory, where the web server usually cannot read', function (): void {
    $autoload = $this->dir.'/home/vendor/autoload.php';
    mkdir($this->dir.'/home/vendor', 0777, true);
    file_put_contents($autoload, '<?php');

    $inHome = managerWithAutoload($this->dir, $this->source, $autoload, $this->dir.'/home')->status(null)->lines['Autoloader'];
    $slash = managerWithAutoload($this->dir, $this->source, $autoload, $this->dir.'/home/')->status(null)->lines['Autoloader'];
    $sibling = managerWithAutoload($this->dir, $this->source, $autoload, $this->dir.'/hom')->status(null)->lines['Autoloader'];

    expect($inHome)->toBe($autoload.' (in the home directory: the web server must be able to read it, or the cache stays off without an error)')
        ->and($slash)->toBe($inHome)
        ->and($sibling)->toBe($autoload);
});

it('is not healthy when the autoloader it names cannot be read', function (): void {
    (new RedisHarness)->store();
    $manager = managerWithAutoload($this->dir, $this->source, $this->dir.'/gone/autoload.php');
    $manager->enable();
    $report = $manager->status(liveSettings());

    expect($report->lines['Autoloader'])->toBe($this->dir.'/gone/autoload.php (not readable by this user)')
        ->and($report->lines['Redis'])->toStartWith('reachable')
        ->and($report->lines['Drop-in'])->toStartWith('current')
        ->and($report->healthy)->toBeFalse();
});

/**
 * A package directory under a vendor directory, with a drop-in to ship; the test directory holds it all.
 */
function packageUnder(string $base, bool $underVendor): string
{
    $root = $underVendor ? $base.'/vendor/pollora/object-cache' : $base.'/pkg';
    mkdir($root.'/resources/drop-in', 0777, true);
    file_put_contents($root.'/resources/drop-in/object-cache.php', "<?php\n// ".DropInInstaller::MARKER." 2\n\$a = '".DropInInstaller::AUTOLOAD_PLACEHOLDER."';\n");

    if ($underVendor) {
        file_put_contents($base.'/vendor/autoload.php', '<?php');
    }

    return $root;
}

it('puts the drop-in in the content directory of the environment, with the autoloader path of the site', function (): void {
    $root = packageUnder($this->dir, true);
    mkdir($this->dir.'/content');

    $manager = Manager::forWordPress(Environments::make(contentDir: $this->dir.'/content/'), $root);
    $manager->enable();

    expect((string) file_get_contents($this->dir.'/content/object-cache.php'))->toContain("\$a = '".$this->dir."/vendor/autoload.php';")
        ->and($manager->status(null)->lines['Drop-in'])->toBe('current ('.$this->dir.'/content/object-cache.php)');
});

it('writes no autoloader path when the package is not under a vendor directory, and defaults to wp-content', function (): void {
    $root = packageUnder($this->dir, false);

    expect(Manager::forWordPress(Environments::make(), $root)->status(null)->lines['Drop-in'])->toBe('missing (wp-content/object-cache.php)');
});

it('uses its own package directory by default', function (): void {
    Manager::forWordPress(Environments::make(contentDir: $this->dir))->enable();

    expect((string) file_get_contents($this->dir.'/object-cache.php'))->toContain(DropInInstaller::MARKER);
});
